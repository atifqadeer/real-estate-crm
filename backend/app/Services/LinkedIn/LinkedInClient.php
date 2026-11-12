<?php
namespace App\Services\LinkedIn;

use Illuminate\Support\Facades\Http;

/**
 * LinkedIn REST API client (Posts API era). Endpoints, headers, scopes
 * and payload shapes verified against LinkedIn's official docs —
 * see docs/12. All /rest calls carry LinkedIn-Version (YYYYMM) and
 * X-Restli-Protocol-Version: 2.0.0 headers.
 */
class LinkedInClient
{
    private string $rest = 'https://api.linkedin.com/rest';
    private string $v2 = 'https://api.linkedin.com/v2';

    public function configured(): bool
    {
        return !empty(config('services.linkedin.client_id')) && !empty(config('services.linkedin.client_secret'));
    }

    public function authorizeUrl(string $state): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => config('services.linkedin.redirect'),
            'state' => $state,
            'scope' => config('services.linkedin.scopes'),
        ]);
        return "https://www.linkedin.com/oauth/v2/authorization?{$params}";
    }

    /** @return array{access_token:string, expires_in:int, refresh_token:?string, scope:?string} */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->acceptJson()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.linkedin.redirect'),
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);
        $json = $response->json() ?? [];
        if ($response->failed() || empty($json['access_token'])) {
            throw new LinkedInApiException('LinkedIn token exchange failed: ' . ($json['error_description'] ?? $json['error'] ?? 'unknown error'), $response->status());
        }
        return [
            'access_token' => $json['access_token'],
            'expires_in' => (int) ($json['expires_in'] ?? 5184000),
            'refresh_token' => $json['refresh_token'] ?? null,
            'scope' => $json['scope'] ?? null,
        ];
    }

    public function userinfo(string $token): array
    {
        return $this->call(fn () => Http::withToken($token)->acceptJson()->get("{$this->v2}/userinfo"));
    }

    /**
     * Pages the member ADMINISTRATES. Per LinkedIn's Community
     * Management integration rules, an integration must only post to
     * organizations where the member is an ADMINISTRATOR, so discovery
     * is filtered to that role.
     */
    public function administeredOrganizations(string $token): array
    {
        $json = $this->call(fn () => Http::withToken($token)->acceptJson()
            ->withHeaders($this->restHeaders())
            ->get("{$this->rest}/organizationAcls", [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(organization~(localizedName,vanityName)))',
            ]));
        $orgs = [];
        foreach ($json['elements'] ?? [] as $el) {
            if (($el['state'] ?? 'APPROVED') !== 'APPROVED') continue;
            $urn = $el['organization'] ?? '';
            $id = str_starts_with($urn, 'urn:li:organization:') ? substr($urn, 20) : null;
            if (!$id) continue;
            $orgs[] = [
                'id' => $id,
                'urn' => $urn,
                'name' => $el['organization~']['localizedName'] ?? ('Organization ' . $id),
                'vanity' => $el['organization~']['vanityName'] ?? null,
            ];
        }
        return $orgs;
    }

    public function getOrganization(string $token, string $organizationId): array
    {
        return $this->call(fn () => Http::withToken($token)->acceptJson()
            ->withHeaders($this->restHeaders())
            ->get("{$this->rest}/organizations/{$organizationId}"));
    }

    /** Create a post; returns the post URN from the x-restli-id header. */
    public function createPost(string $token, array $payload): string
    {
        $response = Http::withToken($token)->acceptJson()->withHeaders($this->restHeaders())
            ->post("{$this->rest}/posts", $payload);
        $this->guard($response, 'create post');
        $id = $response->header('x-restli-id');
        if (!$id) throw new LinkedInApiException('LinkedIn accepted the post but returned no post id.', $response->status());
        return $id;
    }

    /** @return array{upload_url:string, image_urn:string} */
    public function initializeImageUpload(string $token, string $ownerUrn): array
    {
        $json = $this->call(fn () => Http::withToken($token)->acceptJson()->withHeaders($this->restHeaders())
            ->post("{$this->rest}/images?action=initializeUpload", [
                'initializeUploadRequest' => ['owner' => $ownerUrn],
            ]));
        $value = $json['value'] ?? [];
        if (empty($value['uploadUrl']) || empty($value['image'])) {
            throw new LinkedInApiException('LinkedIn image upload initialization returned an unexpected response.');
        }
        return ['upload_url' => $value['uploadUrl'], 'image_urn' => $value['image']];
    }

    public function uploadImageBinary(string $token, string $uploadUrl, string $binary): void
    {
        $response = Http::withToken($token)->withBody($binary, 'application/octet-stream')->put($uploadUrl);
        $this->guard($response, 'upload image binary');
    }

    private function restHeaders(): array
    {
        return [
            'LinkedIn-Version' => (string) config('services.linkedin.version', '202605'),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    private function call(callable $fn): array
    {
        $response = $fn();
        $this->guard($response, 'request');
        return $response->json() ?? [];
    }

    private function guard($response, string $operation): void
    {
        if ($response->failed()) {
            $json = $response->json() ?? [];
            $message = $json['message'] ?? ($json['error_description'] ?? ($json['error'] ?? 'LinkedIn request failed.'));
            throw new LinkedInApiException("LinkedIn {$operation} failed: {$message}", $response->status());
        }
    }
}
