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
            // Phase 7: free-form workspace settings JSON — hosts white-label
            // branding (brand_name, logo_url, primary_color) and feature config.
            'settings' => ['nullable', 'array'],
            'settings.branding' => ['nullable', 'array'],
            'settings.branding.brand_name' => ['nullable', 'string', 'max:80'],
            'settings.branding.logo_url' => ['nullable', 'url', 'max:255'],
            'settings.branding.primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $settings = $organization->settings()->firstOrCreate([]);
        if (isset($data['settings'])) {
            $merged = array_merge($settings->settings ?? [], $data['settings']);
            if (isset($data['settings']['branding'])) {
                $merged['branding'] = array_merge($settings->settings['branding'] ?? [], $data['settings']['branding']);
            }
            $data['settings'] = $merged;
        }
        $settings->update($data);
        $audit->log($organization->id, $request->user()->id, 'settings.updated', ['fields' => array_keys($data)], $request);
        return response()->json(['data' => $settings->fresh()]);
    }
}
