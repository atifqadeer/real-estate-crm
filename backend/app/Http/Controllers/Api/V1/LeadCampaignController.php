<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadCampaign;
use App\Models\Organization;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 8 — Lead Campaigns: per-workspace lead-generation profiles
 * (vertical + keywords + sources) that incoming leads are tagged to,
 * plus the public per-campaign webhook that Google Ads Lead Forms,
 * Zapier/Make or any website can POST leads to.
 */
class LeadCampaignController extends Controller
{
    public function __construct(private LeadIngestionService $ingestion) {}

    /** Industry presets + source options for the setup page. */
    public function presets(Request $request)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        return response()->json(['data' => [
            'verticals' => LeadCampaign::verticalPresets(),
            'sources' => LeadCampaign::sourceOptions(),
        ]]);
    }

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $campaigns = LeadCampaign::where('organization_id', $organization->id)
            ->withCount('leads')
            ->latest()
            ->get()
            ->map(fn (LeadCampaign $c) => $this->present($c, $request));
        return response()->json(['data' => $campaigns]);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.configure'), 403);
        $data = $this->validateCampaign($request);
        $campaign = LeadCampaign::create(array_merge($data, [
            'organization_id' => $organization->id,
            'webhook_key' => Str::random(40),
        ]));
        return response()->json(['data' => $this->present($campaign->loadCount('leads'), $request)], 201);
    }

    public function update(Request $request, Organization $organization, LeadCampaign $campaign)
    {
        abort_unless($campaign->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.configure'), 403);
        $campaign->update($this->validateCampaign($request, partial: true));
        return response()->json(['data' => $this->present($campaign->loadCount('leads'), $request)]);
    }

    public function destroy(Request $request, Organization $organization, LeadCampaign $campaign)
    {
        abort_unless($campaign->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.configure'), 403);
        $campaign->delete(); // leads keep existing; their campaign link nulls on delete FK
        return response()->json(['data' => ['message' => 'Lead campaign deleted.']]);
    }

    public function regenerateWebhook(Request $request, Organization $organization, LeadCampaign $campaign)
    {
        abort_unless($campaign->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.configure'), 403);
        $campaign->update(['webhook_key' => Str::random(40)]);
        return response()->json(['data' => $this->present($campaign->loadCount('leads'), $request)]);
    }

    /**
     * Public lead webhook: POST /api/v1/public/lead-campaigns/{webhook_key}
     * Accepts a flat JSON payload ({full_name, phone, email, city, message,
     * custom_fields:{...}}) or the Google Ads Lead Form shape
     * ({user_column_data: [{column_name, string_value}, ...]}).
     * The unguessable key in the URL is the credential; treat it like a password.
     */
    public function publicWebhook(Request $request, string $webhookKey)
    {
        $campaign = LeadCampaign::withoutTenantScope()
            ->where('webhook_key', $webhookKey)
            ->where('is_active', true)
            ->firstOrFail();

        $data = $this->mapWebhookPayload($request);
        if (empty($data['phone']) && empty($data['whatsapp']) && empty($data['email'])) {
            return response()->json(['error' => ['code' => 'contact_required', 'message' => 'A phone number or email is required.']], 422);
        }

        $organization = $campaign->organization()->firstOrFail();
        $data['lead_campaign_id'] = $campaign->id;
        $data['campaign_name'] = $campaign->name;
        $data['full_name'] = $data['full_name'] ?? 'Webhook Lead';

        $result = $this->ingestion->ingest($organization, $data, 'webhook', null, $request->json()->all() ?: $request->all());
        return response()->json(['data' => ['message' => 'Lead received.', 'outcome' => $result['outcome']]]);
    }

    private function mapWebhookPayload(Request $request): array
    {
        $payload = $request->json()->all() ?: $request->all();

        // Google Ads Lead Form webhook shape.
        if (!empty($payload['user_column_data']) && is_array($payload['user_column_data'])) {
            $data = ['custom_fields' => []];
            foreach ($payload['user_column_data'] as $col) {
                $name = strtoupper((string) ($col['column_name'] ?? $col['column_id'] ?? ''));
                $value = $col['string_value'] ?? null;
                if ($value === null || $value === '') continue;
                match (true) {
                    str_contains($name, 'FULL_NAME') || $name === 'NAME' => $data['full_name'] = $value,
                    str_contains($name, 'FIRST_NAME') => $data['custom_fields']['first_name'] = $value,
                    str_contains($name, 'LAST_NAME') => $data['custom_fields']['last_name'] = $value,
                    str_contains($name, 'PHONE') => $data['phone'] = $value,
                    str_contains($name, 'EMAIL') => $data['email'] = $value,
                    str_contains($name, 'CITY') => $data['city'] = $value,
                    default => $data['custom_fields'][strtolower($name)] = $value,
                };
            }
            if (empty($data['full_name']) && (!empty($data['custom_fields']['first_name']) || !empty($data['custom_fields']['last_name']))) {
                $data['full_name'] = trim(($data['custom_fields']['first_name'] ?? '') . ' ' . ($data['custom_fields']['last_name'] ?? ''));
            }
            if (empty($data['custom_fields'])) unset($data['custom_fields']);
            return $data;
        }

        // Flat shape (Zapier/Make/custom websites).
        $known = ['full_name', 'name', 'phone', 'phone_number', 'whatsapp', 'email', 'city', 'area',
            'preferred_location', 'property_type', 'intent', 'budget_min', 'budget_max',
            'message', 'notes', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $data = [];
        foreach ($known as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) $data[$key] = $payload[$key];
        }
        $data['full_name'] = $data['full_name'] ?? $data['name'] ?? null;
        $data['phone'] = $data['phone'] ?? $data['phone_number'] ?? null;
        $data['notes'] = $data['notes'] ?? $data['message'] ?? null;
        unset($data['name'], $data['phone_number'], $data['message']);
        $custom = (isset($payload['custom_fields']) && is_array($payload['custom_fields'])) ? $payload['custom_fields'] : [];
        foreach ($payload as $key => $value) {
            if (!in_array($key, $known, true) && $key !== 'custom_fields' && is_scalar($value)) {
                $custom[$key] = $value;
            }
        }
        if ($custom) $data['custom_fields'] = $custom;
        return array_filter($data, fn ($v) => $v !== null);
    }

    private function validateCampaign(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$sometimes, 'string', 'max:120'],
            'vertical' => [$sometimes, 'string', 'max:60'],
            'vertical_custom' => ['nullable', 'string', 'max:120'],
            'keywords' => ['nullable', 'array', 'max:100'],
            'keywords.*' => ['string', 'max:80'],
            'locations' => ['nullable', 'array', 'max:50'],
            'locations.*' => ['string', 'max:120'],
            'sources' => ['nullable', 'array', 'max:10'],
            'sources.*' => ['string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function present(LeadCampaign $campaign, Request $request): array
    {
        $data = $campaign->toArray();
        $data['vertical_label'] = $campaign->verticalLabel();
        $data['leads_count'] = $campaign->leads_count ?? $campaign->leads()->count();
        $data['webhook_url'] = url('/api/v1/public/lead-campaigns/' . $campaign->webhook_key);
        return $data;
    }
}
