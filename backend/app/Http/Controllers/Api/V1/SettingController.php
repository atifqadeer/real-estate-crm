<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function show(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('settings.view'), 403);
        return response()->json(['data' => $organization->settings()->firstOrCreate([])]);
    }

    public function update(Request $request, Organization $organization, AuditLogger $audit)
    {
        abort_unless($request->user()->can('settings.update'), 403);
        $data = $request->validate([
            'default_lead_pipeline' => ['nullable', 'array'],
            'approval_rules' => ['nullable', 'array'],
            'notification_settings' => ['nullable', 'array'],
            'lead_assignment_mode' => ['sometimes', 'in:manual,round_robin,location,category'],
            'data_retention_days' => ['nullable', 'integer', 'min:1'],
        ]);
        $settings = $organization->settings()->firstOrCreate([]);
        $settings->update($data);
        $audit->log($organization->id, $request->user()->id, 'settings.updated', ['fields' => array_keys($data)], $request);
        return response()->json(['data' => $settings->fresh()]);
    }
}
