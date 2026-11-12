<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadPipelineStage;
use App\Models\LeadSource;
use App\Models\Organization;
use App\Services\Leads\LeadCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadCatalogController extends Controller
{
    public function __construct(private LeadCatalogService $catalog) {}

    public function stages(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $this->catalog->ensureDefaults($organization);
        return response()->json(['data' => LeadPipelineStage::where('organization_id', $organization->id)->orderBy('sort_order')->get()]);
    }

    public function storeStage(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.configure'), 403);
        $this->catalog->ensureDefaults($organization);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
        ]);
        $stage = LeadPipelineStage::create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
            'sort_order' => LeadPipelineStage::where('organization_id', $organization->id)->max('sort_order') + 1,
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
        ]);
        return response()->json(['data' => $stage], 201);
    }

    public function destroyStage(Request $request, Organization $organization, LeadPipelineStage $stage)
    {
        abort_unless($stage->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.configure'), 403);
        abort_if($stage->is_system, 422, 'Default stages cannot be deleted; rename instead in a later phase.');
        abort_if($stage->leads()->exists(), 422, 'Stage still has leads.');
        $stage->delete();
        return response()->json(['data' => ['message' => 'Stage deleted.']]);
    }

    public function sources(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.view'), 403);
        $this->catalog->ensureDefaults($organization);
        return response()->json(['data' => LeadSource::where('organization_id', $organization->id)->orderBy('name')->get()]);
    }

    public function storeSource(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.configure'), 403);
        $this->catalog->ensureDefaults($organization);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $source = LeadSource::create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'key' => 'custom_' . Str::slug($data['name']) . '_' . Str::lower(Str::random(4)),
        ]);
        return response()->json(['data' => $source], 201);
    }
}
