<?php
namespace App\Services\WhatsApp;

use App\Services\Meta\MetaApiException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Platform (Cloud API) client.
 * Endpoints verified against Meta's official docs — see docs/10.
 */
class WhatsAppClient
{
    private string $base;

    public function __construct()
    {
        $version = config('services.meta.graph_version', 'v27.0');
        $this->base = "https://graph.facebook.com/{$version}";
    }

    /** Verify a phone number node (used at connect time). */
    public function getPhoneNumber(string $phoneNumberId, string $token): array
    {
        return $this->get("/{$phoneNumberId}", ['fields' => 'display_phone_number,verified_name,quality_rating'], $token);
    }

    public function listTemplates(string $wabaId, string $token): array
    {
        $all = [];
        $res = $this->get("/{$wabaId}/message_templates",
            ['fields' => 'name,language,status,category,components', 'limit' => 100], $token);
        for ($page = 0; $page < 5; $page++) {
            $all = array_merge($all, $res['data'] ?? []);
            $next = $res['paging']['next'] ?? null;
            if (!$next) break;
            $res = $this->rawGet($next, $token); // paging.next is a full URL; follow verbatim
        }
        return $all;
    }

    /** Free-form text message — only valid inside the 24h window (enforced by the caller). */
    public function sendText(string $phoneNumberId, string $token, string $toE164, string $body): array
    {
        return $this->post("/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($toE164, '+'),
            'type' => 'text',
            'text' => ['body' => $body],
        ], $token);
    }

    /** Template message — allowed outside the window; params fill {{1}}, {{2}}… */
    public function sendTemplate(string $phoneNumberId, string $token, string $toE164, string $templateName, string $language, array $bodyParams = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($toE164, '+'),
            'type' => 'template',
            'template' => ['name' => $templateName, 'language' => ['code' => $language]],
        ];
        if ($bodyParams) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $bodyParams),
            ]];
        }
        return $this->post("/{$phoneNumberId}/messages", $payload, $token);
    }

    public function markRead(string $phoneNumberId, string $token, string $waMessageId): array
    {
        return $this->post("/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
        ], $token);
    }

    private function get(string $path, array $params, string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->get($this->base . $path, $params);
        return $this->decode($response);
    }

    private function rawGet(string $url, string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->get($url);
        return $this->decode($response);
    }

    private function post(string $path, array $payload, string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->post($this->base . $path, $payload);
        return $this->decode($response);
    }

    private function decode($response): array
    {
        $json = $response->json() ?? [];
        if ($response->failed() || isset($json['error'])) {
            $error = $json['error'] ?? [];
            throw new MetaApiException(
                $error['message'] ?? 'WhatsApp API request failed.',
                (int) ($error['code'] ?? $response->status())
            );
        }
        return $json;
    }
}
