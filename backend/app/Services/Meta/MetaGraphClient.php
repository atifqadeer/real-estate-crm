<?php
namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Meta Graph API (Facebook Login path).
 * Endpoints verified against Meta's official docs — see docs/07.
 * All calls are server-side; the app secret never leaves the server.
 */
class MetaGraphClient
{
    public function version(): string
    {
        return (string) config('services.meta.graph_version', 'v27.0');
    }

    public function base(): string
    {
        return "https://graph.facebook.com/{$this->version()}";
    }

    public function appId(): string { return (string) config('services.meta.app_id'); }
    public function appSecret(): string { return (string) config('services.meta.app_secret'); }
    public function redirectUri(): string { return (string) config('services.meta.redirect'); }

    public function configured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '' && $this->redirectUri() !== '';
    }

    /** Step 1 URL: Facebook Login dialog. */
    public function dialogUrl(string $state, bool $rerequest = false): string
    {
        $params = [
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => (string) config('services.meta.scopes'),
        ];
        if ($rerequest) {
            $params['auth_type'] = 'rerequest';
        }
        return "https://www.facebook.com/{$this->version()}/dialog/oauth?" . http_build_query($params);
    }

    private function get(string $path, array $params): array
    {
        $response = Http::acceptJson()->timeout(20)->get($this->base() . $path, $params);
        $body = $response->json() ?? [];
        if ($response->failed() || isset($body['error'])) {
            throw MetaApiException::fromResponse($body);
        }
        return $body;
    }

    /** Exchange the OAuth code for a short-lived user token. */
    public function exchangeCode(string $code): array
    {
        return $this->get('/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);
    }

    /** Exchange a valid user token for a long-lived (~60 day) user token. */
    public function exchangeLongLived(string $userToken): array
    {
        return $this->get('/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $userToken,
        ]);
    }

    /** Validate a token and read its scopes/expiry (app token = app_id|app_secret). */
    public function debugToken(string $token): array
    {
        $body = $this->get('/debug_token', [
            'input_token' => $token,
            'access_token' => $this->appId() . '|' . $this->appSecret(),
        ]);
        return $body['data'] ?? [];
    }

    public function me(string $userToken): array
    {
        return $this->get('/me', ['fields' => 'id,name', 'access_token' => $userToken]);
    }

    /**
     * Pages the person manages, each with its Page access token and the
     * linked Instagram professional account, when one exists.
     */
    public function accounts(string $userToken): array
    {
        $body = $this->get('/me/accounts', [
            'fields' => 'id,name,username,picture{url},access_token,instagram_business_account{id,username}',
            'limit' => 100,
            'access_token' => $userToken,
        ]);
        return $body['data'] ?? [];
    }

    /** Read a Page or Instagram node with a Page token (test connection). */
    public function node(string $externalId, string $token, string $fields = 'id,name'): array
    {
        return $this->get("/{$externalId}", ['fields' => $fields, 'access_token' => $token]);
    }

    /** POST form data to the Graph API (publishing). Token is passed as a field, never logged by this client. */
    public function post(string $path, array $params): array
    {
        $response = Http::acceptJson()->timeout(60)->asForm()->post($this->base() . $path, $params);
        $body = $response->json() ?? [];
        if ($response->failed() || isset($body['error'])) {
            throw MetaApiException::fromResponse($body, "Meta API POST {$path} failed (HTTP {$response->status()}).");
        }
        return $body;
    }

    /** GET with an explicit access token (container status, permalinks). */
    public function getWithToken(string $path, array $params, string $token): array
    {
        return $this->get($path, array_merge($params, ['access_token' => $token]));
    }

    /** POST form data to an alternate Graph host (e.g. graph-video.facebook.com). */
    public function postToHost(string $host, string $path, array $params): array
    {
        $version = $this->version();
        $response = Http::acceptJson()->timeout(120)->asForm()->post("{$host}/{$version}{$path}", $params);
        $body = $response->json() ?? [];
        if ($response->failed() || isset($body['error'])) {
            throw MetaApiException::fromResponse($body, "Meta API POST {$path} failed (HTTP {$response->status()}).");
        }
        return $body;
    }

    /** Resumable Upload API: start a video upload session (official Video API guide). */
    public function startUploadSession(string $fileName, int $fileLength, string $fileType, string $token): array
    {
        return $this->post("/{$this->appId()}/uploads", [
            'file_name' => $fileName,
            'file_length' => $fileLength,
            'file_type' => $fileType,
            'access_token' => $token,
        ]);
    }

    /** Resumable Upload API: send the file bytes for a session; returns the file handle. */
    public function uploadVideoChunk(string $sessionId, string $binary, string $token): string
    {
        $version = $this->version();
        $response = Http::timeout(300)
            ->withHeaders(['file_offset' => '0', 'Authorization' => 'OAuth ' . $token])
            ->withBody($binary, 'application/octet-stream')
            ->post("https://graph-video.facebook.com/{$version}/upload:{$sessionId}");
        $body = $response->json() ?? [];
        if ($response->failed() || !isset($body['h'])) {
            throw MetaApiException::fromResponse($body, 'Video upload chunk failed.');
        }
        return (string) $body['h'];
    }

    // ---- Lead Ads (Phase 4; endpoints verified in docs/09) ----

    /** Retrieve one lead by its leadgen id. Requires leads_retrieval. */
    public function fetchLead(string $leadgenId, string $token): array
    {
        return $this->getWithToken("/{$leadgenId}", [
            'fields' => 'created_time,id,ad_id,adgroup_id,campaign_id,form_id,field_data',
        ], $token);
    }

    /** List leads of a lead ad form (Bulk Read edge), one page of results. */
    public function formLeads(string $formId, string $token, ?string $after = null): array
    {
        $params = ['fields' => 'created_time,id,ad_id,form_id,field_data', 'limit' => 100];
        if ($after) $params['after'] = $after;
        return $this->getWithToken("/{$formId}/leads", $params, $token);
    }

    /** Install the app on a Page for the leadgen webhook field. */
    public function subscribePageToLeadgen(string $pageId, string $pageToken): array
    {
        return $this->post("/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'leadgen',
            'access_token' => $pageToken,
        ]);
    }

    public function fetchAdName(string $adId, string $token): ?string
    {
        try {
            $node = $this->getWithToken("/{$adId}", ['fields' => 'name'], $token);
            return $node['name'] ?? null;
        } catch (MetaApiException) {
            return null; // attribution nicety only
        }
    }

    /**
     * Permanent errors must not be retried (auth, permission, validation,
     * policy). Rate limits and server faults are transient.
     */
    public static function isPermanent(MetaApiException $e): bool
    {
        return in_array($e->graphCode, [100, 190, 200, 294, 10, 2200, 803], true);
    }
}
