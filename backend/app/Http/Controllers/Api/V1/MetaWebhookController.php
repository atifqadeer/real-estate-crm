<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaLeadEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Meta Webhooks endpoint (public). Verification handshake + leadgen
 * event intake. See docs/09 for the verified protocol details.
 */
class MetaWebhookController extends Controller
{
    /** GET verification: echo hub.challenge when the verify token matches. */
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

    /** POST events: validate signature, store, dispatch async processing, always 200 fast. */
    public function receive(Request $request)
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
        if (($payload['object'] ?? null) === 'page') {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? null) !== 'leadgen') continue;
                    $value = $change['value'] ?? [];
                    $leadgenId = (string) ($value['leadgen_id'] ?? '');
                    if ($leadgenId === '') continue;
                    $event = WebhookEvent::firstOrCreate(
                        ['provider' => 'meta', 'event_key' => $leadgenId],
                        ['payload' => ['entry_id' => $entry['id'] ?? null, 'value' => $value], 'status' => 'received']
                    );
                    if ($event->wasRecentlyCreated) {
                        ProcessMetaLeadEvent::dispatch($event->id);
                    }
                }
            }
        }
        return response()->json(['status' => 'ok']);
    }
}
