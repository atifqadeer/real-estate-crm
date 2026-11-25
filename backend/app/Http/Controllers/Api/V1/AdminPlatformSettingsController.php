<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlatformSettings;
use Illuminate\Http\Request;

/**
 * Platform Settings page API: the Meta / LinkedIn / AI app credentials
 * the whole platform runs on. Agency staff only. Values are saved to
 * the platform_settings table (secrets encrypted, never returned);
 * database values override .env. A blank secret field on save means
 * "keep the existing value"; send null to clear a value entirely.
 */
class AdminPlatformSettingsController extends Controller
{
    public function __construct(private PlatformSettings $settings) {}

    public function show(Request $request)
    {
        abort_unless($request->user()->is_agency_staff, 403);
        return response()->json(['data' => $this->payload()]);
    }

    private function payload(): array
    {
        return [
            'groups' => $this->settings->status(),
            'urls' => [
                'meta_oauth_callback' => url('/api/v1/social/meta/callback'),
                'meta_webhook' => url('/api/v1/webhooks/meta'),
                'linkedin_oauth_callback' => url('/api/v1/social/linkedin/callback'),
                'whatsapp_webhook' => url('/api/v1/webhooks/whatsapp'),
            ],
        ];
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->is_agency_staff, 403);
        $data = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data['values'] as $key => $value) {
            $field = PlatformSettings::field($key);
            if (!$field) continue; // unknown keys are ignored, never stored
            // Blank secret = keep existing (Laravel turns '' into null in transit).
            if ($field['secret'] && ($value === '' || $value === null)) continue;
            $this->settings->set($key, $value === '' ? null : $value);
        }

        return response()->json(['data' => array_merge($this->payload(), [
            'message' => 'Platform settings saved.',
        ])]);
    }
}
