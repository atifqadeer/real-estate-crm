<?php
namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform-wide integration settings (Meta / LinkedIn / AI app
 * credentials), managed from the Platform Settings page and stored in
 * the platform_settings table. The database is the ONLY source for
 * credentials — there are deliberately no environment variables for
 * them. Secrets are encrypted at rest and never returned by the API.
 * Applied to config at boot (AppServiceProvider) so every existing
 * consumer of config('services.*') picks them up; config holds only
 * non-secret operational defaults (API versions, scopes, endpoints).
 */
class PlatformSettings
{
    /** @var array<string, ?string>|null per-request cache of decrypted values */
    private ?array $cache = null;

    public static function definitions(): array
    {
        return [
            'meta' => [
                'label' => 'Meta (Facebook & Instagram)',
                'fields' => [
                    'meta_app_id' => ['config' => 'services.meta.app_id', 'label' => 'App ID', 'secret' => false, 'help' => 'From developers.facebook.com → your app → Settings → Basic.'],
                    'meta_app_secret' => ['config' => 'services.meta.app_secret', 'label' => 'App Secret', 'secret' => true, 'help' => 'Same page as the App ID. Server-side only; never shared.'],
                    'meta_redirect_uri' => ['config' => 'services.meta.redirect', 'label' => 'OAuth Redirect URI', 'secret' => false, 'help' => 'Must exactly match the Valid OAuth Redirect URI saved in the Meta app (Facebook Login → Settings).'],
                    'meta_webhook_verify_token' => ['config' => 'services.meta.webhook_verify_token', 'label' => 'Webhook Verify Token', 'secret' => true, 'help' => 'Any long random string you invent; enter the same value in the Meta app Webhooks config.'],
                    'meta_graph_version' => ['config' => 'services.meta.graph_version', 'label' => 'Graph API Version', 'secret' => false, 'help' => 'e.g. v27.0'],
                    'meta_scopes' => ['config' => 'services.meta.scopes', 'label' => 'OAuth Scopes', 'secret' => false, 'help' => 'Comma separated. leads_retrieval is required to fetch Lead Ads leads; pages_manage_metadata is required to subscribe a Page to leadgen webhooks.'],
                ],
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'fields' => [
                    'linkedin_client_id' => ['config' => 'services.linkedin.client_id', 'label' => 'Client ID', 'secret' => false, 'help' => 'From linkedin.com/developers → your app → Auth.'],
                    'linkedin_client_secret' => ['config' => 'services.linkedin.client_secret', 'label' => 'Client Secret', 'secret' => true, 'help' => 'Same page as the Client ID.'],
                    'linkedin_redirect_uri' => ['config' => 'services.linkedin.redirect', 'label' => 'OAuth Redirect URI', 'secret' => false, 'help' => 'Must exactly match an Authorized redirect URL in the LinkedIn app.'],
                    'linkedin_scopes' => ['config' => 'services.linkedin.scopes', 'label' => 'OAuth Scopes', 'secret' => false, 'help' => 'Space separated. Organization posting needs the Community Management API product approved.'],
                    'linkedin_version' => ['config' => 'services.linkedin.version', 'label' => 'API Version', 'secret' => false, 'help' => 'e.g. 202605'],
                ],
            ],
            'ai' => [
                'label' => 'AI Content Assistant (OpenAI-compatible)',
                'fields' => [
                    'ai_api_url' => ['config' => 'services.ai.url', 'label' => 'API Base URL', 'secret' => false, 'help' => 'e.g. https://api.openai.com/v1'],
                    'ai_api_key' => ['config' => 'services.ai.key', 'label' => 'API Key', 'secret' => true, 'help' => 'Without a key the assistant returns an honest "not configured" error.'],
                    'ai_model' => ['config' => 'services.ai.model', 'label' => 'Model', 'secret' => false, 'help' => 'e.g. gpt-4o-mini'],
                ],
            ],
        ];
    }

    public static function field(string $key): ?array
    {
        foreach (self::definitions() as $group) {
            if (isset($group['fields'][$key])) return $group['fields'][$key];
        }
        return null;
    }

    /** Decrypted database value for a key, or null when not set in the DB. */
    public function get(string $key): ?string
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach (PlatformSetting::all() as $row) {
                $value = $row->value;
                if ($value !== null && $row->is_secret) {
                    try { $value = Crypt::decryptString($value); } catch (\Throwable) { $value = null; }
                }
                $this->cache[$row->key] = $value;
            }
        }
        return $this->cache[$key] ?? null;
    }

    /** Store a value (null clears it). Secrets are encrypted. */
    public function set(string $key, ?string $value): void
    {
        $field = self::field($key);
        $isSecret = (bool) ($field['secret'] ?? false);
        if ($value === null || $value === '') {
            PlatformSetting::where('key', $key)->delete();
        } else {
            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $isSecret ? Crypt::encryptString($value) : $value, 'is_secret' => $isSecret]
            );
        }
        $this->cache = null;
        // Keep the live config in step within this request too.
        if ($field && $value) config([$field['config'] => $value]);
    }

    /** Apply database values to config('services.*') — the database is the only credential source. */
    public function applyToConfig(): void
    {
        foreach (self::definitions() as $group) {
            foreach ($group['fields'] as $key => $field) {
                $value = $this->get($key);
                if ($value !== null && $value !== '') {
                    config([$field['config'] => $value]);
                }
            }
        }
    }

    /**
     * Status for the settings page: for every field, whether it is set,
     * where from (database / env), and its value when it is not secret.
     */
    public function status(): array
    {
        $groups = [];
        foreach (self::definitions() as $groupKey => $group) {
            $fields = [];
            $allSet = true;
            foreach ($group['fields'] as $key => $field) {
                $dbValue = $this->get($key);
                $defaultValue = config($field['config']); // non-secret operational default, if any
                $isSet = ($dbValue !== null && $dbValue !== '') || ($defaultValue !== null && $defaultValue !== '');
                if (!$isSet) $allSet = false;
                $fields[] = [
                    'key' => $key,
                    'label' => $field['label'],
                    'secret' => $field['secret'],
                    'help' => $field['help'],
                    'is_set' => $isSet,
                    'source' => ($dbValue !== null && $dbValue !== '') ? 'database' : ($isSet ? 'default' : null),
                    'value' => $field['secret'] ? null : ($dbValue ?? $defaultValue),
                ];
            }
            $groups[] = ['key' => $groupKey, 'label' => $group['label'], 'configured' => $allSet, 'fields' => $fields];
        }
        return $groups;
    }
}
