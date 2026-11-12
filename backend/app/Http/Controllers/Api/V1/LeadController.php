<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadPipelineStage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\LeadCatalogService;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function __construct(
        private LeadIngestionService $ingestion,
        private LeadAssignmentService $assignment,
        private LeadCatalogService $catalog,
    ) {}

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $this->catalog->ensureDefaults($organization);
        $leads = Lead::where('organization_id', $organization->id)
            ->with(['source:id,name,key', 'stage:id,name,slug', 'assignee:id,name'])
            ->when($request->query('stage_id'), fn ($q, $v) => $q->where('lead_pipeline_stage_id', $v))
            ->when($request->query('source_id'), fn ($q, $v) => $q->where('lead_source_id', $v))
            ->when($request->query('assigned_to') === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->query('assigned_to') && $request->query('assigned_to') !== 'me', fn ($q) => $q->where('assigned_to', $request->query('assigned_to')))
            ->when($request->boolean('possible_duplicates'), fn ($q) => $q->whereNotNull('possible_duplicate_of'))
            ->when($request->query('search'), function ($q, $s) {
                $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")
                    ->orWhere('phone_e164', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('city', 'like', "%{$s}%"));
            })
            ->latest()->paginate(25);
        return response()->json($leads);
    }

    /** Manual lead creation goes through the same ingestion/dedupe path as every source. */
    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.create'), 403);
        $data = $this->validated($request);
        $sourceKey = $data['source_key'] ?? 'manual';
        unset($data['source_key']);
        $result = $this->ingestion->ingest($organization, $data, $sourceKey, $request->user());
        $lead = $result['lead']->load(['source:id,name,key', 'stage:id,name,slug', 'assignee:id,name']);
        return response()->json(['data' => $lead, 'outcome' => $result['outcome']], $result['outcome'] === 'created' ? 201 : 200);
    }

    public function show(Request $request, Organization $organization, Lead $lead)
    {
        $this->guard($organization, $lead);
        abort_unless($request->user()->can('leads.view'), 403);
        $lead->load([
            'source:id,name,key', 'stage:id,name,slug', 'assignee:id,name',
            'touchpoints.source:id,name', 'activities.actor:id,name', 'followUps.user:id,name',
            'possibleDuplicateOf:id,full_name,phone_e164',
        ]);
        return response()->json(['data' => $lead]);
    }

    public function update(Request $request, Organization $organization, Lead $lead)
    {
        $this->guard($organization, $lead);
        abort_unless($request->user()->can('leads.edit'), 403);
        $data = $this->validated($request, partial: true);

        if (isset($data['lead_pipeline_stage_id'])) {
            $stage = LeadPipelineStage::where('organization_id', $organization->id)->findOrFail($data['lead_pipeline_stage_id']);
            if ($stage->id !== $lead->lead_pipeline_stage_id) {
                $old = $lead->stage?->name;
                $lead->update(['lead_pipeline_stage_id' => $stage->id]);
                LeadActivity::create([
                    'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => $request->user()->id,
                    'type' => 'stage_changed', 'description' => "Stage: {$old} → {$stage->name}.",
                ]);
            }
            unset($data['lead_pipeline_stage_id']);
        }
        if (isset($data['assigned_to'])) {
            $agent = User::findOrFail($data['assigned_to']);
            abort_unless($organization->users()->whereKey($agent->id)->exists(), 422, 'Assignee is not a member of this workspace.');
            $this->assignment->manualAssign($lead, $agent, $request->user());
            unset($data['assigned_to']);
        }
        if ($data) $lead->update($data);
        return response()->json(['data' => $lead->fresh()->load(['source:id,name,key', 'stage:id,name,slug', 'assignee:id,name'])]);
    }

    public function destroy(Request $request, Organization $organization, Lead $lead)
    {
        $this->guard($organization, $lead);
        abort_unless($request->user()->can('leads.delete'), 403);
        $lead->delete();
        return response()->json(['data' => ['message' => 'Lead deleted.']]);
    }

    public function addNote(Request $request, Organization $organization, Lead $lead)
    {
        $this->guard($organization, $lead);
        abort_unless($request->user()->can('leads.edit'), 403);
        $data = $request->validate(['note' => ['required', 'string'], 'mark_contacted' => ['sometimes', 'boolean']]);
        LeadActivity::create([
            'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => $request->user()->id,
            'type' => 'note', 'description' => $data['note'],
        ]);
        if ($data['mark_contacted'] ?? false) $lead->update(['last_contacted_at' => now()]);
        return response()->json(['data' => $lead->fresh()]);
    }

    /** Agent dashboard summary (prompt section 14). */
    public function mySummary(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $this->catalog->ensureDefaults($organization);
        $mine = Lead::where('organization_id', $organization->id)->where('assigned_to', $request->user()->id);
        $stageCount = fn (callable $fn) => (clone $mine)->whereHas('stage', $fn)->count();
        return response()->json(['data' => [
            'my_total' => (clone $mine)->count(),
            'my_new' => $stageCount(fn ($q) => $q->where('slug', 'new')),
            'my_contacted' => $stageCount(fn ($q) => $q->where('slug', 'contacted')),
            'my_qualified' => $stageCount(fn ($q) => $q->whereIn('slug', ['qualified', 'interested'])),
            'my_viewings' => $stageCount(fn ($q) => $q->where('slug', 'viewing_scheduled')),
            'my_converted' => $stageCount(fn ($q) => $q->where('is_won', true)),
            'my_lost' => $stageCount(fn ($q) => $q->where('is_lost', true)),
            'followups_due_today' => \App\Models\LeadFollowUp::where('organization_id', $organization->id)
                ->where('user_id', $request->user()->id)->where('status', 'pending')
                ->whereDate('due_at', today())->count(),
            'followups_overdue' => \App\Models\LeadFollowUp::where('organization_id', $organization->id)
                ->where('user_id', $request->user()->id)->where('status', 'pending')
                ->where('due_at', '<', now()->startOfDay())->count(),
            'unassigned_total' => Lead::where('organization_id', $organization->id)->whereNull('assigned_to')->count(),
        ]]);
    }

    /** Backfill existing leads of a Meta lead form (Bulk Read) into this workspace. */
    public function metaBackfill(Request $request, Organization $organization, \App\Services\Leads\MetaLeadService $metaLeads)
    {
        abort_unless($request->user()->can('leads.import'), 403);
        $data = $request->validate([
            'social_account_id' => ['required', 'integer'],
            'form_id' => ['required', 'string', 'max:64'],
        ]);
        $account = \App\Models\SocialAccount::where('organization_id', $organization->id)
            ->whereHas('platform', fn ($q) => $q->where('key', 'facebook'))
            ->findOrFail($data['social_account_id']);
        try {
            $counts = $metaLeads->backfillForm($account, $data['form_id']);
        } catch (\App\Services\Meta\MetaApiException $e) {
            return response()->json(['error' => ['code' => 'backfill_failed', 'message' => $e->getMessage()]], 502);
        }
        return response()->json(['data' => $counts]);
    }

    private function guard(Organization $organization, Lead $lead): void
    {
        abort_unless($lead->organization_id === $organization->id, 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'full_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone_e164' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'whatsapp_e164' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:160'],
            'property_type' => ['nullable', 'string', 'max:120'],
            'property_reference' => ['nullable', 'string', 'max:160'],
            'intent' => ['nullable', 'in:buy,sell,rent,invest'],
            'preferred_location' => ['nullable', 'string', 'max:190'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'budget_currency' => ['nullable', 'string', 'size:3'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'campaign_name' => ['nullable', 'string', 'max:190'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'consent_marketing' => ['sometimes', 'boolean'],
            'consent_whatsapp' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'custom_fields' => ['nullable', 'array'],
            'source_key' => ['nullable', 'string', 'max:60'],
            'lead_pipeline_stage_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
        ];
        $data = $request->validate($rules);
        if (!$partial && empty($data['phone']) && empty($data['phone_e164']) && empty($data['email'])) {
            throw ValidationException::withMessages(['phone' => 'A lead needs at least a phone number or an email.']);
        }
        return $data;
    }
}
