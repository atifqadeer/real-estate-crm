<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppTemplate;
use App\Services\Meta\MetaApiException;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function accounts(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('whatsapp.view'), 403);
        return response()->json(['data' => WhatsAppAccount::where('organization_id', $organization->id)->get()]);
    }

    public function connect(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('whatsapp.connect'), 403);
        $data = $request->validate([
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'access_token' => ['required', 'string'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
        ]);
        try {
            $account = $this->whatsapp->connect($organization, $request->user(), $data);
        } catch (MetaApiException $e) {
            return response()->json(['error' => ['code' => 'connect_failed', 'message' => 'Meta rejected these credentials: ' . $e->getMessage()]], 422);
        }
        return response()->json(['data' => $account], 201);
    }

    public function disconnect(Request $request, Organization $organization, WhatsAppAccount $account)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('whatsapp.connect'), 403);
        $account->update(['status' => 'disconnected', 'access_token' => 'revoked']);
        return response()->json(['data' => ['message' => 'WhatsApp account disconnected.']]);
    }

    public function templates(Request $request, Organization $organization, WhatsAppAccount $account)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('whatsapp.templates'), 403);
        return response()->json(['data' => $account->templates()->orderBy('name')->get()]);
    }

    public function syncTemplates(Request $request, Organization $organization, WhatsAppAccount $account)
    {
        $this->guardAccount($organization, $account);
        abort_unless($request->user()->can('whatsapp.connect'), 403);
        try {
            $count = $this->whatsapp->syncTemplates($account);
        } catch (MetaApiException $e) {
            return response()->json(['error' => ['code' => 'sync_failed', 'message' => $e->getMessage()]], 502);
        }
        return response()->json(['data' => ['synced' => $count]]);
    }

    public function conversations(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('whatsapp.view'), 403);
        $items = WhatsAppConversation::where('organization_id', $organization->id)
            ->with(['lead:id,full_name,assigned_to', 'account:id,display_phone_number'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')->paginate(30);
        return response()->json($items);
    }

    public function conversation(Request $request, Organization $organization, WhatsAppConversation $conversation)
    {
        $this->guardConversation($organization, $conversation);
        abort_unless($request->user()->can('whatsapp.view'), 403);
        $conversation->update(['unread_count' => 0]);
        $conversation->load([
            'lead:id,full_name,phone_e164,assigned_to',
            'account:id,display_phone_number,verified_name',
            'messages' => fn ($q) => $q->orderBy('created_at'),
        ]);
        // Best-effort blue ticks: mark the latest inbound message as read at Meta.
        $latestInbound = $conversation->messages->where('direction', 'in')->last();
        if ($latestInbound?->wa_message_id && $conversation->account->status === 'connected') {
            try {
                app(\App\Services\WhatsApp\WhatsAppClient::class)->markRead(
                    $conversation->account->phone_number_id, $conversation->account->access_token, $latestInbound->wa_message_id);
            } catch (\Throwable) { /* read receipts are cosmetic; never fail the inbox */ }
        }
        return response()->json(['data' => $conversation]);
    }

    /** Start (or fetch) the conversation for a lead on a chosen account. */
    public function startForLead(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('whatsapp.view'), 403);
        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'whatsapp_account_id' => ['required', 'integer'],
        ]);
        $lead = Lead::where('organization_id', $organization->id)->findOrFail($data['lead_id']);
        $account = WhatsAppAccount::where('organization_id', $organization->id)->findOrFail($data['whatsapp_account_id']);
        $phone = $lead->whatsapp_e164 ?? $lead->phone_e164;
        if (!$phone) {
            return response()->json(['error' => ['code' => 'no_phone', 'message' => 'This lead has no phone number.']], 422);
        }
        $conversation = $this->whatsapp->conversationFor($account, $phone, $lead, $lead->full_name);
        return response()->json(['data' => $conversation]);
    }

    public function sendText(Request $request, Organization $organization, WhatsAppConversation $conversation)
    {
        $this->guardConversation($organization, $conversation);
        abort_unless($request->user()->can('whatsapp.send'), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        $message = $this->whatsapp->sendText($conversation, $request->user(), $data['body']);
        return response()->json(['data' => $message], $message->status === 'failed' ? 502 : 201);
    }

    public function sendTemplate(Request $request, Organization $organization, WhatsAppConversation $conversation)
    {
        $this->guardConversation($organization, $conversation);
        abort_unless($request->user()->can('whatsapp.send'), 403);
        $data = $request->validate([
            'template_name' => ['required', 'string', 'max:120'],
            'language' => ['required', 'string', 'max:16'],
            'params' => ['nullable', 'array'],
            'params.*' => ['string', 'max:300'],
        ]);
        $message = $this->whatsapp->sendTemplate($conversation, $request->user(), $data['template_name'], $data['language'], $data['params'] ?? []);
        return response()->json(['data' => $message], $message->status === 'failed' ? 502 : 201);
    }

    private function guardAccount(Organization $organization, WhatsAppAccount $account): void
    {
        abort_unless($account->organization_id === $organization->id, 404);
    }

    private function guardConversation(Organization $organization, WhatsAppConversation $conversation): void
    {
        abort_unless($conversation->organization_id === $organization->id, 404);
    }
}
