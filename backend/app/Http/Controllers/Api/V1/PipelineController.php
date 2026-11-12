<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadPipelineStage;
use App\Models\Organization;
use App\Services\Leads\LeadCatalogService;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function __construct(private LeadCatalogService $catalog) {}

    /** Kanban board: every stage with its lead cards. */
    public function board(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $this->catalog->ensureDefaults($organization);
        $stages = LeadPipelineStage::where('organization_id', $organization->id)->orderBy('sort_order')->get();
        $leads = Lead::where('organization_id', $organization->id)
            ->with(['source:id,name', 'assignee:id,name'])
            ->when($request->query('assigned_to') === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->latest()->get()
            ->groupBy('lead_pipeline_stage_id');
        return response()->json(['data' => $stages->map(fn ($stage) => [
            'stage' => $stage,
            'count' => ($leads->get($stage->id) ?? collect())->count(),
            'leads' => ($leads->get($stage->id) ?? collect())->map(fn (Lead $l) => [
                'id' => $l->id, 'full_name' => $l->full_name, 'phone_e164' => $l->phone_e164,
                'city' => $l->city, 'preferred_location' => $l->preferred_location,
                'property_reference' => $l->property_reference, 'priority' => $l->priority,
                'budget_max' => $l->budget_max, 'source' => $l->source?->name,
                'assignee' => $l->assignee?->name, 'created_at' => $l->created_at,
                'possible_duplicate_of' => $l->possible_duplicate_of,
            ])->values(),
        ])]);
    }

    /** Move a card to another stage (drag & drop target). */
    public function move(Request $request, Organization $organization, Lead $lead)
    {
        abort_unless($lead->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.edit'), 403);
        $data = $request->validate(['stage_id' => ['required', 'integer']]);
        $stage = LeadPipelineStage::where('organization_id', $organization->id)->findOrFail($data['stage_id']);
        if ($stage->id === $lead->lead_pipeline_stage_id) {
            return response()->json(['data' => $lead]);
        }
        $old = $lead->stage?->name;
        $lead->update(['lead_pipeline_stage_id' => $stage->id, 'last_contacted_at' => $lead->last_contacted_at]);
        LeadActivity::create([
            'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => $request->user()->id,
            'type' => 'stage_changed', 'description' => "Moved on pipeline: {$old} → {$stage->name}.",
        ]);
        return response()->json(['data' => $lead->fresh()]);
    }
}
