<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadFollowUp;
use App\Models\Organization;
use Illuminate\Http\Request;

class LeadFollowUpController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.followups'), 403);
        $items = LeadFollowUp::where('organization_id', $organization->id)
            ->with(['lead:id,full_name,phone_e164', 'user:id,name'])
            ->when($request->query('scope') === 'mine', fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->query('scope') === 'overdue', fn ($q) => $q->where('status', 'pending')->where('due_at', '<', now()))
            ->when($request->query('scope') === 'today', fn ($q) => $q->where('status', 'pending')->whereDate('due_at', today()))
            ->when($request->query('lead_id'), fn ($q, $v) => $q->where('lead_id', $v))
            ->orderBy('due_at')->paginate(30);
        return response()->json($items);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.followups'), 403);
        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'due_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:300'],
            'user_id' => ['nullable', 'integer'],
        ]);
        $lead = Lead::where('organization_id', $organization->id)->findOrFail($data['lead_id']);
        $followUp = LeadFollowUp::create([
            'organization_id' => $organization->id,
            'lead_id' => $lead->id,
            'user_id' => $data['user_id'] ?? $lead->assigned_to ?? $request->user()->id,
            'due_at' => $data['due_at'],
            'note' => $data['note'] ?? null,
        ]);
        $lead->update(['next_follow_up_at' => $data['due_at']]);
        LeadActivity::create([
            'organization_id' => $organization->id, 'lead_id' => $lead->id, 'actor_id' => $request->user()->id,
            'type' => 'follow_up_created', 'description' => 'Follow-up scheduled for ' . $data['due_at'] . (!empty($data['note']) ? ": {$data['note']}" : ''),
        ]);
        return response()->json(['data' => $followUp], 201);
    }

    public function complete(Request $request, Organization $organization, LeadFollowUp $followUp)
    {
        abort_unless($followUp->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.followups'), 403);
        $followUp->update(['status' => 'done', 'completed_at' => now()]);
        LeadActivity::create([
            'organization_id' => $organization->id, 'lead_id' => $followUp->lead_id, 'actor_id' => $request->user()->id,
            'type' => 'follow_up_done', 'description' => 'Follow-up completed.',
        ]);
        return response()->json(['data' => $followUp]);
    }

    public function cancel(Request $request, Organization $organization, LeadFollowUp $followUp)
    {
        abort_unless($followUp->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.followups'), 403);
        $followUp->update(['status' => 'cancelled']);
        return response()->json(['data' => $followUp]);
    }
}
