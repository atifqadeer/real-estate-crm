<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Request;

/**
 * WhatsApp Cloud API webhooks (public). Same verification handshake and
 * HMAC signature scheme as other Meta webhooks — see docs/10.
 * Payload: object "whatsapp_business_account", changes field "messages".
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $expected = (string) config('services.meta.webhook_verify_token');
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }
        return response('Verification failed', 403);
    }

    public function receive(Request $request, WhatsAppService $whatsapp)
    {
        $secret = (string) config('services.meta.app_secret');
        if ($secret !== '') {
            $signature = (string) $request->header('X-Hub-Signature-256', '');
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($expected, $signature)) {
                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }
        $payload = $request->json()->all();
        if (($payload['object'] ?? null) === 'whatsapp_business_account') {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? null) === 'messages') {
                        $whatsapp->processWebhookValue($change['value'] ?? []);
                    }
                }
            }
        }
        return response()->json(['status' => 'ok']);
    }
}
