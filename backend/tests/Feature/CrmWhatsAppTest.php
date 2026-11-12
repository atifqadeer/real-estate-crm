<?php
namespace Tests\Feature;

use App\Jobs\CheckFollowUpNotifications;
use App\Models\InAppNotification;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrmWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Organization $clientA;
    private Organization $clientB;
    private User $adminA;
    private User $adminB;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SocialPlatformSeeder::class);
        config(['services.meta.app_secret' => 'testsecret', 'services.meta.webhook_verify_token' => 'vtok']);
        $roles = app(RoleProvisioner::class);
        $mk = function () use ($roles) {
            $org = Organization::factory()->create();
            $roles->provisionFor($org);
            $org->settings()->create([]);
            return $org;
        };
        $this->clientA = $mk();
        $this->clientB = $mk();
        $member = function (Organization $org, string $role) use ($roles) {
            $user = User::factory()->create();
            $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
            $roles->assign($org, $user, $role);
            return $user;
        };
        $this->adminA = $member($this->clientA, 'client_admin');
        $this->adminB = $member($this->clientB, 'client_admin');
        $this->agent = $member($this->clientA, 'client_sales_agent');
    }

    private function auth(User $user, Organization $org): array
    {
        auth()->forgetGuards(); // shared test app caches the first guard user
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken, 'X-Organization-Id' => (string) $org->id];
    }

    private function makeLead(array $overrides = []): Lead
    {
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads", array_merge([
            'full_name' => 'Ahmed Raza', 'phone' => '0300-1234567',
        ], $overrides), $this->auth($this->adminA, $this->clientA));
        $res->assertCreated();
        return Lead::find($res->json('data.id'));
    }

    private function connectWhatsApp(): WhatsAppAccount
    {
        Http::fake([
            // Narrow pattern (with ?fields=) so it cannot shadow the /pn-1/messages stub later.
            'graph.facebook.com/v27.0/pn-1?fields=*' => Http::response([
                'display_phone_number' => '+92 300 9998877', 'verified_name' => 'Client A Estates',
                'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1000',
            ]),
        ]);
        $res = $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/accounts", [
            'waba_id' => 'waba-1', 'phone_number_id' => 'pn-1', 'access_token' => 'wa-token-secret',
        ], $this->auth($this->adminA, $this->clientA));
        $res->assertCreated();
        return WhatsAppAccount::find($res->json('data.id'));
    }

    private function sendWebhook(array $value): void
    {
        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba-1',
            'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'testsecret');
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();
    }

    private function inboundValue(string $body, string $waId = 'wamid.in-1', string $from = '923001234567'): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '+92 300 9998877', 'phone_number_id' => 'pn-1'],
            'contacts' => [['profile' => ['name' => 'Ahmed Raza'], 'wa_id' => $from]],
            'messages' => [[
                'from' => $from, 'id' => $waId, 'timestamp' => (string) time(), 'type' => 'text',
                'text' => ['body' => $body],
            ]],
        ];
    }

    public function test_pipeline_board_and_move(): void
    {
        $lead1 = $this->makeLead();
        $this->makeLead(['full_name' => 'Second Lead', 'phone' => '0321-7654321']);

        $board = $this->getJson("/api/v1/organizations/{$this->clientA->id}/pipeline", $this->auth($this->adminA, $this->clientA));
        $board->assertOk();
        $stages = collect($board->json('data'));
        $this->assertSame(8, $stages->count());
        $this->assertSame(2, $stages->firstWhere('stage.slug', 'new')['count']);

        $qualified = $stages->firstWhere('stage.slug', 'qualified')['stage']['id'];
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/leads/{$lead1->id}/move", ['stage_id' => $qualified], $this->auth($this->adminA, $this->clientA))->assertOk();
        $this->assertSame($qualified, $lead1->fresh()->lead_pipeline_stage_id);
        $this->assertTrue($lead1->activities()->where('type', 'stage_changed')->exists());
    }

    public function test_saved_filters_visibility(): void
    {
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-filters", [
            'name' => 'My hot leads', 'criteria' => ['stage_id' => 1], 'is_shared' => false,
        ], $this->auth($this->agent, $this->clientA))->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/lead-filters", [
            'name' => 'Team view', 'criteria' => ['search' => 'DHA'], 'is_shared' => true,
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();

        $agentList = $this->getJson("/api/v1/organizations/{$this->clientA->id}/lead-filters", $this->auth($this->agent, $this->clientA));
        $this->assertCount(2, $agentList->json('data')); // own private + shared
        $adminList = $this->getJson("/api/v1/organizations/{$this->clientA->id}/lead-filters", $this->auth($this->adminA, $this->clientA));
        $this->assertCount(1, $adminList->json('data')); // only own shared one
    }

    public function test_tasks_notify_assignee_and_complete(): void
    {
        $lead = $this->makeLead();
        $task = $this->postJson("/api/v1/organizations/{$this->clientA->id}/tasks", [
            'title' => 'Call the buyer', 'lead_id' => $lead->id, 'assigned_to' => $this->agent->id,
            'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ], $this->auth($this->adminA, $this->clientA))->assertCreated()->json('data');

        $this->assertTrue(InAppNotification::where('user_id', $this->agent->id)->where('type', 'task.assigned')->exists());

        $mine = $this->getJson("/api/v1/organizations/{$this->clientA->id}/tasks?scope=mine", $this->auth($this->agent, $this->clientA));
        $this->assertSame(1, $mine->json('total'));

        $done = $this->putJson("/api/v1/organizations/{$this->clientA->id}/tasks/{$task['id']}", ['status' => 'done'], $this->auth($this->agent, $this->clientA));
        $done->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertNotNull($done->json('data.completed_at'));
    }

    public function test_assignment_notification_and_preference_optout(): void
    {
        $lead = $this->makeLead(); // round-robin assigns to the only sales agent and notifies
        $count = InAppNotification::where('user_id', $this->agent->id)->where('type', 'lead.assigned')->count();
        $this->assertSame(1, $count);

        // Agent disables lead.assigned notifications in this workspace.
        $this->putJson("/api/v1/organizations/{$this->clientA->id}/notification-preferences", [
            'disabled_types' => ['lead.assigned'],
        ], $this->auth($this->agent, $this->clientA))->assertOk();

        $lead2 = $this->makeLead(['full_name' => 'Quiet Lead', 'phone' => '0333-1112223']);
        $this->assertSame(1, InAppNotification::where('user_id', $this->agent->id)->where('type', 'lead.assigned')->count());

        $list = $this->getJson("/api/v1/organizations/{$this->clientA->id}/notifications", $this->auth($this->agent, $this->clientA));
        $list->assertOk()->assertJsonPath('unread_count', 1);
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/notifications/read-all", [], $this->auth($this->agent, $this->clientA))->assertOk();
        $list2 = $this->getJson("/api/v1/organizations/{$this->clientA->id}/notifications", $this->auth($this->agent, $this->clientA));
        $this->assertSame(0, $list2->json('unread_count'));
    }

    public function test_followup_due_and_overdue_notifications_run_once(): void
    {
        $lead = $this->makeLead();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/follow-ups", [
            'lead_id' => $lead->id, 'due_at' => now()->addMinutes(30)->format('Y-m-d H:i:s'), 'user_id' => $this->agent->id,
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/follow-ups", [
            'lead_id' => $lead->id, 'due_at' => now()->subDays(1)->format('Y-m-d H:i:s'), 'user_id' => $this->agent->id,
        ], $this->auth($this->adminA, $this->clientA))->assertCreated();

        (new CheckFollowUpNotifications)->handle(app(\App\Services\Notifications\NotificationService::class));
        (new CheckFollowUpNotifications)->handle(app(\App\Services\Notifications\NotificationService::class));

        $this->assertSame(1, InAppNotification::where('user_id', $this->agent->id)->where('type', 'followup.due')->count());
        $this->assertSame(1, InAppNotification::where('user_id', $this->agent->id)->where('type', 'followup.overdue')->count());
    }

    public function test_whatsapp_connect_verifies_and_hides_token(): void
    {
        $account = $this->connectWhatsApp();
        $this->assertSame('Client A Estates', $account->verified_name);
        $this->assertSame('GREEN', $account->quality_rating);
        $raw = DB::table('whatsapp_accounts')->value('access_token');
        $this->assertNotSame('wa-token-secret', $raw); // encrypted at rest

        $list = $this->getJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/accounts", $this->auth($this->adminA, $this->clientA));
        $list->assertOk();
        $this->assertStringNotContainsString('wa-token-secret', $list->getContent());
    }

    public function test_template_sync_and_template_send_rules(): void
    {
        $account = $this->connectWhatsApp();
        Http::fake([
            'graph.facebook.com/v27.0/waba-1/message_templates*' => Http::response(['data' => [
                ['name' => 'property_intro', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Assalam-o-Alaikum {{1}}, {{2}} is available.']]],
                ['name' => 'draft_promo', 'language' => 'en', 'status' => 'PENDING', 'category' => 'MARKETING', 'components' => []],
            ]]),
            'graph.facebook.com/v27.0/pn-1/messages*' => Http::response(['messages' => [['id' => 'wamid.out-1']]]),
        ]);
        $sync = $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/accounts/{$account->id}/sync-templates", [], $this->auth($this->adminA, $this->clientA));
        $sync->assertOk()->assertJsonPath('data.synced', 2);

        $lead = $this->makeLead();
        $conv = $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/start", [
            'lead_id' => $lead->id, 'whatsapp_account_id' => $account->id,
        ], $this->auth($this->adminA, $this->clientA))->assertOk()->json('data');

        // Pending template cannot be sent.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/{$conv['id']}/send-template", [
            'template_name' => 'draft_promo', 'language' => 'en',
        ], $this->auth($this->adminA, $this->clientA))->assertStatus(422);

        // Approved template sends fine even with the window closed.
        $sent = $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/{$conv['id']}/send-template", [
            'template_name' => 'property_intro', 'language' => 'en', 'params' => ['Ahmed', 'DHA Phase 6 plot'],
        ], $this->auth($this->adminA, $this->clientA));
        $sent->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.wa_message_id', 'wamid.out-1');
    }

    public function test_free_text_requires_open_window(): void
    {
        $account = $this->connectWhatsApp();
        Http::fake([
            'graph.facebook.com/v27.0/pn-1/messages*' => Http::response(['messages' => [['id' => 'wamid.out-2']]]),
        ]);
        $lead = $this->makeLead();
        $conv = $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/start", [
            'lead_id' => $lead->id, 'whatsapp_account_id' => $account->id,
        ], $this->auth($this->adminA, $this->clientA))->json('data');

        // Window closed -> free text rejected with template guidance.
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/{$conv['id']}/send-text", [
            'body' => 'Hello!',
        ], $this->auth($this->adminA, $this->clientA))->assertStatus(422);

        // Customer messages us -> window opens for 24h.
        $this->sendWebhook($this->inboundValue('I am interested in this plot'));
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/{$conv['id']}/send-text", [
            'body' => 'Great! When would you like a viewing?',
        ], $this->auth($this->agent, $this->clientA))->assertCreated();

        // 25 hours later the window is closed again.
        $this->travel(25)->hours();
        $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/{$conv['id']}/send-text", [
            'body' => 'Following up',
        ], $this->auth($this->agent, $this->clientA))->assertStatus(422);
    }

    public function test_inbound_webhook_creates_lead_conversation_notification_and_statuses(): void
    {
        $this->connectWhatsApp();
        $this->sendWebhook($this->inboundValue('Assalam-o-Alaikum, price please?', 'wamid.in-9', '923214445555'));

        $lead = Lead::where('phone_e164', '+923214445555')->first();
        $this->assertNotNull($lead);
        $this->assertSame('whatsapp', $lead->source->key);

        $conversation = WhatsAppConversation::where('contact_phone_e164', '+923214445555')->first();
        $this->assertNotNull($conversation);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertTrue($conversation->windowOpen());
        $this->assertSame(1, $conversation->messages()->where('direction', 'in')->count());

        // The assignee (round robin -> the only sales agent) is notified.
        $this->assertTrue(InAppNotification::where('user_id', $this->agent->id)->where('type', 'whatsapp.message')->exists());

        // Redelivery does not duplicate the message.
        $this->sendWebhook($this->inboundValue('Assalam-o-Alaikum, price please?', 'wamid.in-9', '923214445555'));
        $this->assertSame(1, $conversation->messages()->where('direction', 'in')->count());

        // Status webhook updates an outbound message.
        $out = WhatsAppMessage::create([
            'organization_id' => $this->clientA->id, 'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'out', 'wa_message_id' => 'wamid.out-9', 'type' => 'text', 'body' => 'Hi', 'status' => 'sent',
        ]);
        $this->sendWebhook([
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => 'pn-1'],
            'statuses' => [['id' => 'wamid.out-9', 'status' => 'read', 'timestamp' => (string) time(), 'recipient_id' => '923214445555']],
        ]);
        $this->assertSame('read', $out->fresh()->status);
    }

    public function test_opt_out_and_opt_in(): void
    {
        $account = $this->connectWhatsApp();
        Http::fake([
            'graph.facebook.com/v27.0/pn-1/messages*' => Http::response(['messages' => [['id' => 'wamid.out-3']]]),
        ]);
        $this->sendWebhook($this->inboundValue('Hello', 'wamid.in-20'));
        $conversation = WhatsAppConversation::first();
        $lead = $conversation->lead;

        $this->sendWebhook($this->inboundValue('STOP', 'wamid.in-21'));
        $this->assertNotNull($conversation->fresh()->opted_out_at);
        $this->assertFalse($lead->fresh()->consent_whatsapp);

        $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/{$conversation->id}/send-template", [
            'template_name' => 'property_intro', 'language' => 'en',
        ], $this->auth($this->adminA, $this->clientA))->assertStatus(422);

        $this->sendWebhook($this->inboundValue('START', 'wamid.in-22'));
        $this->assertNull($conversation->fresh()->opted_out_at);
        $this->assertTrue($lead->fresh()->consent_whatsapp);
    }

    public function test_whatsapp_tenant_isolation(): void
    {
        $account = $this->connectWhatsApp();
        $lead = $this->makeLead();
        $conv = $this->postJson("/api/v1/organizations/{$this->clientA->id}/whatsapp/conversations/start", [
            'lead_id' => $lead->id, 'whatsapp_account_id' => $account->id,
        ], $this->auth($this->adminA, $this->clientA))->json('data');

        $this->getJson("/api/v1/organizations/{$this->clientB->id}/whatsapp/conversations/{$conv['id']}", $this->auth($this->adminB, $this->clientB))->assertNotFound();
        $accounts = $this->getJson("/api/v1/organizations/{$this->clientB->id}/whatsapp/accounts", $this->auth($this->adminB, $this->clientB));
        $this->assertCount(0, $accounts->json('data'));
    }
}
