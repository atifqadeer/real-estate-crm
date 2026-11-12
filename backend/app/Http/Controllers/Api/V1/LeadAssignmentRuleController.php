<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadAssignmentRule;
use App\Models\Organization;
use Illuminate\Http\Request;

class LeadAssignmentRuleController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.configure'), 403);
        $rules = LeadAssignmentRule::where('organization_id', $organization->id)
            ->with('assignee:id,name')->orderBy('sort_order')->get();
        return response()->json(['data' => $rules]);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.configure'), 403);
        $data = $request->validate([
            'match_type' => ['required', 'in:city,property_type,source,default'],
            'match_value' => ['nullable', 'string', 'max:160'],
            'assign_to_user_id' => ['required', 'integer'],
        ]);
        abort_unless($organization->users()->whereKey($data['assign_to_user_id'])->exists(), 422, 'Assignee is not a member of this workspace.');
        $rule = LeadAssignmentRule::create(array_merge($data, [
            'organization_id' => $organization->id,
            'sort_order' => LeadAssignmentRule::where('organization_id', $organization->id)->max('sort_order') + 1,
        ]));
        return response()->json(['data' => $rule->load('assignee:id,name')], 201);
    }

    public function destroy(Request $request, Organization $organization, LeadAssignmentRule $rule)
    {
        abort_unless($rule->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.configure'), 403);
        $rule->delete();
        return response()->json(['data' => ['message' => 'Rule deleted.']]);
    }
}
