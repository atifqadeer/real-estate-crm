<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadSavedFilter;
use App\Models\Organization;
use Illuminate\Http\Request;

class LeadSavedFilterController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $filters = LeadSavedFilter::where('organization_id', $organization->id)
            ->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('is_shared', true))
            ->latest()->get();
        return response()->json(['data' => $filters]);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'criteria' => ['required', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);
        $filter = LeadSavedFilter::create(array_merge($data, [
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
        ]));
        return response()->json(['data' => $filter], 201);
    }

    public function destroy(Request $request, Organization $organization, LeadSavedFilter $filter)
    {
        abort_unless($filter->organization_id === $organization->id, 404);
        abort_unless($filter->user_id === $request->user()->id || $request->user()->can('leads.configure'), 403);
        $filter->delete();
        return response()->json(['data' => ['message' => 'Saved filter deleted.']]);
    }
}
