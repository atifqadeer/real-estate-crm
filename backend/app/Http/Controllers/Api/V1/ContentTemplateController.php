<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentTemplate;
use App\Models\Organization;
use Illuminate\Http\Request;

class ContentTemplateController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.view'), 403);
        $templates = ContentTemplate::where('organization_id', $organization->id)->orderBy('category')->orderBy('name')->get();
        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.create'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:120'],
            'caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'string'],
        ]);
        $template = ContentTemplate::create(array_merge($data, [
            'organization_id' => $organization->id, 'author_id' => $request->user()->id,
        ]));
        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, Organization $organization, ContentTemplate $template)
    {
        abort_unless($template->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('content.edit'), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:120'],
            'caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'string'],
        ]);
        $template->update(array_merge($data, ['is_system' => false]));
        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, Organization $organization, ContentTemplate $template)
    {
        abort_unless($template->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('content.delete'), 403);
        $template->delete();
        return response()->json(['data' => ['message' => 'Template deleted.']]);
    }

    /** Load the starter template set (prompt section 10) into this workspace. */
    public function seedStarters(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.create'), 403);
        $created = 0;
        foreach (ContentTemplate::starterTemplates() as $t) {
            $exists = ContentTemplate::where('organization_id', $organization->id)->where('name', $t['name'])->exists();
            if (!$exists) {
                ContentTemplate::create(array_merge($t, ['organization_id' => $organization->id, 'is_system' => true]));
                $created++;
            }
        }
        return response()->json(['data' => ['created' => $created]]);
    }
}
