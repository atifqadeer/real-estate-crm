<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiContentGeneration;
use App\Models\Organization;
use App\Services\Ai\AiContentService;
use Illuminate\Http\Request;

class AiContentController extends Controller
{
    public function suggest(Request $request, Organization $organization, AiContentService $ai)
    {
        abort_unless($request->user()->can('ai.use'), 403);
        $data = $request->validate([
            'platform' => ['nullable', 'in:facebook,instagram,linkedin,whatsapp'],
            'language' => ['nullable', 'in:en,ur'],
            'objective' => ['nullable', 'string', 'max:300'],
            'property' => ['required', 'array'],
            'property.title' => ['required', 'string', 'max:160'],
            'property.property_type' => ['nullable', 'string', 'max:80'],
            'property.city' => ['nullable', 'string', 'max:80'],
            'property.area' => ['nullable', 'string', 'max:120'],
            'property.price' => ['nullable', 'string', 'max:60'],
            'property.bedrooms' => ['nullable', 'string', 'max:20'],
            'property.bathrooms' => ['nullable', 'string', 'max:20'],
            'property.size' => ['nullable', 'string', 'max:60'],
            'property.features' => ['nullable', 'string', 'max:500'],
            'property.contact' => ['nullable', 'string', 'max:80'],
        ]);
        return response()->json(['data' => $ai->suggest($organization, $request->user(), $data)]);
    }

    public function history(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('ai.use'), 403);
        return response()->json(['data' => AiContentGeneration::where('organization_id', $organization->id)->latest('id')->limit(20)->get()]);
    }
}
