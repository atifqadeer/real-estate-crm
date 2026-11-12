<?php
namespace App\Services\Leads;

use App\Models\SocialAccount;
use App\Models\WebhookEvent;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaGraphClient;

/**
 * Meta Lead Ads retrieval: webhook event -> GET /{leadgen_id} ->
 * normalized ingestion (dedupe handled centrally). Also the form
 * backfill (Bulk Read). Endpoints verified in docs/09.
 */
class MetaLeadService
{
    public function __construct(
        private MetaGraphClient $graph,
        private LeadIngestionService $ingestion,
    ) {}

    public function processEvent(WebhookEvent $event): void
    {
        $value = $event->payload['value'] ?? [];
        $leadgenId = (string) ($value['leadgen_id'] ?? $event->event_key);
        $pageId = (string) ($value['page_id'] ?? '');

        $account = SocialAccount::withoutTenantScope()
            ->where('external_id', $pageId)
            ->whereHas('platform', fn ($q) => $q->where('key', 'facebook'))
            ->with('token')->first();

        if (!$account) {
            $event->update(['status' => 'ignored', 'error' => "No connected Facebook Page with id {$pageId} in any workspace.", 'processed_at' => now()]);
            return;
        }
        $event->update(['organization_id' => $account->organization_id, 'social_account_id' => $account->id]);

        $token = $account->token?->access_token;
        if (!$token) {
            $event->update(['status' => 'failed', 'error' => 'Page has no stored token; reconnect it.', 'processed_at' => now()]);
            return;
        }

        try {
            $leadData = $this->graph->fetchLead($leadgenId, $token);
        } catch (MetaApiException $e) {
            $event->update(['status' => 'failed', 'error' => $e->getMessage(), 'processed_at' => now()]);
            return;
        }

        $this->ingestFetchedLead($account, $leadData, $value, $token);
        $event->update(['status' => 'processed', 'error' => null, 'processed_at' => now()]);
    }

    /** Backfill all leads of one form into the account's workspace. Returns outcome counts. */
    public function backfillForm(SocialAccount $account, string $formId): array
    {
        $token = $account->token?->access_token;
        if (!$token) throw new MetaApiException('Page has no stored token; reconnect it.');
        $counts = ['fetched' => 0, 'created' => 0, 'touchpoints' => 0, 'possible_duplicates' => 0, 'duplicates' => 0];
        $after = null;
        for ($page = 0; $page < 10; $page++) {
            $res = $this->graph->formLeads($formId, $token, $after);
            foreach ($res['data'] ?? [] as $leadData) {
                $counts['fetched']++;
                $outcome = $this->ingestFetchedLead($account, $leadData, ['page_id' => $account->external_id, 'form_id' => $formId], $token);
                match ($outcome) {
                    'created' => $counts['created']++,
                    'touchpoint' => $counts['touchpoints']++,
                    'possible_duplicate' => $counts['possible_duplicates']++,
                    'duplicate_event' => $counts['duplicates']++,
                };
            }
            $after = $res['paging']['cursors']['after'] ?? null;
            if (!$after || empty($res['data'])) break;
        }
        return $counts;
    }

    private function ingestFetchedLead(SocialAccount $account, array $leadData, array $webhookValue, string $token): string
    {
        $fields = [];
        foreach ($leadData['field_data'] ?? [] as $fd) {
            $fields[$fd['name']] = $fd['values'][0] ?? null;
        }

        $adId = $leadData['ad_id'] ?? $webhookValue['ad_id'] ?? null;
        $data = [
            'full_name' => $fields['full_name'] ?? $fields['name'] ?? 'Meta Lead',
            'email' => $fields['email'] ?? null,
            'phone' => $fields['phone_number'] ?? $fields['phone'] ?? null,
            'whatsapp' => $fields['whatsapp_number'] ?? null,
            'city' => $fields['city'] ?? null,
            'meta_lead_id' => (string) ($leadData['id'] ?? $webhookValue['leadgen_id']),
            'meta_form_id' => (string) ($leadData['form_id'] ?? $webhookValue['form_id'] ?? ''),
            'meta_page_id' => (string) ($webhookValue['page_id'] ?? $account->external_id),
            'meta_campaign_id' => isset($leadData['campaign_id']) ? (string) $leadData['campaign_id'] : null,
            'meta_ad_id' => $adId ? (string) $adId : null,
            'meta_adgroup_id' => isset($leadData['adgroup_id']) ? (string) $leadData['adgroup_id'] : (isset($webhookValue['adgroup_id']) ? (string) $webhookValue['adgroup_id'] : null),
            'ad_name' => $adId ? $this->graph->fetchAdName((string) $adId, $token) : null,
        ];
        // Everything else the form asked becomes custom fields (property questions etc.).
        $known = ['full_name', 'name', 'email', 'phone_number', 'phone', 'whatsapp_number', 'city'];
        $custom = array_diff_key($fields, array_flip($known));
        if ($custom) $data['custom_fields'] = $custom;

        $result = $this->ingestion->ingest($account->organization, $data, 'meta_lead_ads', null, ['field_data' => $leadData['field_data'] ?? []]);
        return $result['outcome'];
    }
}
