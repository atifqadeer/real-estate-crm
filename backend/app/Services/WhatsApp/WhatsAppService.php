<?php
namespace App\Services\WhatsApp;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Leads\LeadIngestionService;
use App\Services\Meta\MetaApiException;
use App\Services\Notifications\NotificationService;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp conversation engine: connect, template sync, sending with
 * template/session/opt-in rules enforced (prompt section 12C), and
 * inbound webhook processing with lead matching per the dedupe rules.
 */
class WhatsAppService
{
    private const OPT_OUT_WORDS = ['stop', 'unsubscribe', 'opt out', 'optout', 'band karo', 'rok do'];
    private const OPT_IN_WORDS = ['start', 'subscribe', 'resume'];

    public function __construct(
        private WhatsAppClient $client,
        private LeadIngestionService $ingestion,
        private NotificationService $notifications,
    ) {}

    public function connect(Organization $organization, User $actor, array $data): WhatsAppAccount
    {
        // Verify the number with Meta before storing anything.
        $node = $this->client->getPhoneNumber($data['phone_number_id'], $data['access_token']);
        return WhatsAppAccount::updateOrCreate(
            ['phone_number_id' => $data['phone_number_id']],
            [
                'organization_id' => $organization->id,
                'waba_id' => $data['waba_id'],
                'display_phone_number' => $node['display_phone_number'] ?? ($data['display_phone_number'] ?? null),
                'verified_name' => $node['verified_name'] ?? null,
                'quality_rating' => $node['quality_rating'] ?? null,
                'messaging_limit' => isset($node['messaging_limit_tier']) ? (string) $node['messaging_limit_tier'] : null,
                'access_token' => $data['access_token'],
                'status' => 'connected',
                'connected_by' => $actor->id,
            ]
        );
    }

    public function syncTemplates(WhatsAppAccount $account): int
    {
        $templates = $this->client->listTemplates($account->waba_id, $account->access_token);
        $count = 0;
        foreach ($templates as $t) {
            \App\Models\WhatsAppTemplate::updateOrCreate(
                ['whatsapp_account_id' => $account->id, 'name' => $t['name'], 'language' => $t['language']],
                [
                    'organization_id' => $account->organization_id,
                    'category' => $t['category'] ?? null,
                    'status' => $t['status'] ?? null,
                    'components' => $t['components'] ?? null,
                    'synced_at' => now(),
                ]
            );
            $count++;
        }
        return $count;
    }

    /** Open (or reuse) the conversation for a lead/phone on an account. */
    public function conversationFor(WhatsAppAccount $account, string $phoneE164, ?Lead $lead = null, ?string $contactName = null): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::withoutTenantScope()->firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'contact_phone_e164' => $phoneE164],
            ['organization_id' => $account->organization_id, 'contact_name' => $contactName]
        );
        if ($lead && !$conversation->lead_id) {
            $conversation->update(['lead_id' => $lead->id]);
        }
        if ($contactName && !$conversation->contact_name) {
            $conversation->update(['contact_name' => $contactName]);
        }
        return $conversation;
    }

    /**
     * Send a free-form text reply. Rules enforced here:
     *  - opted-out contacts are never messaged
     *  - outside the 24h customer service window, free-form text is
     *    rejected with guidance to use a template instead
     */
    public function sendText(WhatsAppConversation $conversation, User $sender, string $body): WhatsAppMessage
    {
        $this->guardSendable($conversation);
        if (!$conversation->windowOpen()) {
            throw ValidationException::withMessages(['window' =>
                'The 24-hour customer service window is closed for this contact. Send an approved template message to reopen the conversation.']);
        }
        return $this->deliver($conversation, $sender, function (WhatsAppAccount $account) use ($conversation, $body) {
            return $this->client->sendText($account->phone_number_id, $account->access_token, $conversation->contact_phone_e164, $body);
        }, ['type' => 'text', 'body' => $body]);
    }

    /** Send an approved template (the only way to start or reopen a conversation outside the window). */
    public function sendTemplate(WhatsAppConversation $conversation, User $sender, string $templateName, string $language, array $params = []): WhatsAppMessage
    {
        $this->guardSendable($conversation);
        $template = $conversation->account->templates()
            ->where('name', $templateName)->where('language', $language)->first();
        if ($template && strtoupper((string) $template->status) !== 'APPROVED') {
            throw ValidationException::withMessages(['template' => "Template {$templateName} is not APPROVED (status: {$template->status})."]);
        }
        $message = $this->deliver($conversation, $sender, function (WhatsAppAccount $account) use ($conversation, $templateName, $language, $params) {
            return $this->client->sendTemplate($account->phone_number_id, $account->access_token, $conversation->contact_phone_e164, $templateName, $language, $params);
        }, ['type' => 'template', 'body' => $template?->bodyText(), 'template_name' => $templateName]);
        // A template message starts a conversation but the 24h *session*
        // window only opens when the customer replies; leave it unchanged.
        return $message;
    }

    private function guardSendable(WhatsAppConversation $conversation): void
    {
        if ($conversation->opted_out_at) {
            throw ValidationException::withMessages(['opt_out' => 'This contact has opted out of WhatsApp messages. Do not message them.']);
        }
        if ($conversation->lead && $conversation->lead->consent_whatsapp === false && $conversation->lead->activities()->where('type', 'whatsapp_opt_out')->exists()) {
            throw ValidationException::withMessages(['opt_out' => 'This lead has opted out of WhatsApp messages.']);
        }
        if ($conversation->account->status !== 'connected') {
            throw ValidationException::withMessages(['account' => 'WhatsApp account is not connected.']);
        }
    }

    private function deliver(WhatsAppConversation $conversation, User $sender, callable $send, array $record): WhatsAppMessage
    {
        $account = $conversation->account;
        try {
            $res = $send($account);
            $waId = $res['messages'][0]['id'] ?? null;
            $message = WhatsAppMessage::create(array_merge($record, [
                'organization_id' => $conversation->organization_id,
                'whatsapp_conversation_id' => $conversation->id,
                'direction' => 'out',
                'wa_message_id' => $waId,
                'status' => 'sent',
                'sent_by' => $sender->id,
                'raw' => $res,
            ]));
        } catch (MetaApiException $e) {
            $message = WhatsAppMessage::create(array_merge($record, [
                'organization_id' => $conversation->organization_id,
                'whatsapp_conversation_id' => $conversation->id,
                'direction' => 'out',
                'status' => 'failed',
                'error' => $e->getMessage(),
                'sent_by' => $sender->id,
            ]));
        }
        $conversation->update(['last_message_at' => now(), 'status' => 'open']);
        return $message;
    }

    /**
     * Process one inbound webhook change value (messages + statuses).
     * Returns a small summary for logging/tests.
     */
    public function processWebhookValue(array $value): array
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $account = WhatsAppAccount::withoutTenantScope()->where('phone_number_id', $phoneNumberId)->first();
        if (!$account) return ['ignored' => 'unknown phone_number_id'];
        $organization = $account->organization()->first();
        if (!$organization) return ['ignored' => 'account has no organization'];

        $summary = ['messages' => 0, 'statuses' => 0];
        $profiles = [];
        foreach ($value['contacts'] ?? [] as $c) {
            $profiles[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? null;
        }

        foreach ($value['messages'] ?? [] as $msg) {
            $summary['messages']++;
            $this->handleInboundMessage($account, $organization, $msg, $profiles[$msg['from'] ?? ''] ?? null);
        }
        foreach ($value['statuses'] ?? [] as $status) {
            $summary['statuses']++;
            if (!empty($status['id']) && !empty($status['status'])) {
                WhatsAppMessage::where('wa_message_id', $status['id'])->update(['status' => $status['status']]);
            }
        }
        return $summary;
    }

    private function handleInboundMessage(WhatsAppAccount $account, Organization $organization, array $msg, ?string $profileName): void
    {
        $from = '+' . ltrim((string) ($msg['from'] ?? ''), '+');
        $waId = $msg['id'] ?? null;
        if ($waId && WhatsAppMessage::where('wa_message_id', $waId)->exists()) {
            return; // webhook redelivery
        }

        // Match an existing lead by phone/WhatsApp; otherwise create one
        // through the central ingestion path (source: whatsapp).
        $lead = Lead::withoutTenantScope()->where('organization_id', $organization->id)
            ->where(fn ($q) => $q->where('phone_e164', $from)->orWhere('whatsapp_e164', $from))
            ->first();
        if (!$lead) {
            $result = $this->ingestion->ingest($organization, [
                'full_name' => $profileName ?: 'WhatsApp contact',
                'phone' => $from,
                'whatsapp' => $from,
                'consent_whatsapp' => true,
            ], 'whatsapp');
            $lead = $result['lead'];
        }

        $conversation = $this->conversationFor($account, $from, $lead, $profileName);
        $type = $msg['type'] ?? 'other';
        $body = $msg['text']['body'] ?? ($msg['button']['text'] ?? null);
        $mediaId = $msg[$type]['id'] ?? null;

        WhatsAppMessage::create([
            'organization_id' => $organization->id,
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'in',
            'wa_message_id' => $waId,
            'type' => $type,
            'body' => $body,
            'media_url' => $mediaId ? "media:{$mediaId}" : null,
            'status' => 'received',
            'raw' => $msg,
        ]);
        $conversation->update([
            'last_message_at' => now(),
            'window_expires_at' => now()->addHours(24), // any customer message opens the 24h window
            'unread_count' => $conversation->unread_count + 1,
            'status' => 'open',
        ]);

        // Opt-out / opt-in keywords (consent handling).
        $normalized = strtolower(trim((string) $body));
        if ($body && in_array($normalized, self::OPT_OUT_WORDS, true)) {
            $conversation->update(['opted_out_at' => now()]);
            $lead->update(['consent_whatsapp' => false]);
            \App\Models\LeadActivity::create([
                'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => null,
                'type' => 'whatsapp_opt_out', 'description' => "Contact opted out of WhatsApp messages (sent '{$body}').",
            ]);
        } elseif ($body && in_array($normalized, self::OPT_IN_WORDS, true) && $conversation->opted_out_at) {
            $conversation->update(['opted_out_at' => null]);
            $lead->update(['consent_whatsapp' => true]);
            \App\Models\LeadActivity::create([
                'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => null,
                'type' => 'whatsapp_opt_in', 'description' => 'Contact opted back in to WhatsApp messages.',
            ]);
        }

        \App\Models\LeadActivity::create([
            'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => null,
            'type' => 'whatsapp_message', 'description' => 'WhatsApp message received' . ($body ? ": {$body}" : " ({$type})"),
        ]);

        // Notify the assignee (or sales managers when unassigned).
        $recipients = $lead->assignee ? [$lead->assignee] : $this->notifications->usersWithRoles($organization, ['client_sales_manager', 'client_admin']);
        $this->notifications->notify($organization, $recipients, 'whatsapp.message',
            'WhatsApp message from ' . ($profileName ?: $from),
            $body ? mb_substr($body, 0, 140) : "({$type} message)",
            ['lead_id' => $lead->id, 'conversation_id' => $conversation->id]);
    }
}
