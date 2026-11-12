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
}
