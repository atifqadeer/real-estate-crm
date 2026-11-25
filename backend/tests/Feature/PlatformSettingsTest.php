<?php
namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Meta\MetaGraphClient;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $agency;
    private User $staff;
    private User $clientAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $provisioner = app(RoleProvisioner::class);
        $this->agency = Organization::factory()->create(['type' => 'agency']);
        $provisioner->provisionFor($this->agency);
        $this->agency->settings()->create([]);

        $this->staff = User::create(['name' => 'Staff', 'email' => 'staff@test.pk', 'password' => bcrypt('password'), 'is_agency_staff' => true]);
        $this->agency->users()->attach($this->staff->id, ['status' => 'active', 'joined_at' => now()]);

        $client = Organization::factory()->create(['type' => 'real_estate_client']);
        $provisioner->provisionFor($client);
        $client->settings()->create([]);
        $this->clientAdmin = User::create(['name' => 'Client', 'email' => 'client@test.pk', 'password' => bcrypt('password')]);
        $client->users()->attach($this->clientAdmin->id, ['status' => 'active', 'joined_at' => now()]);
        $provisioner->assign($client, $this->clientAdmin, 'client_admin');
    }

    private function auth(User $user): array
    {
        auth()->forgetGuards();
        return ['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken];
    }

    public function test_only_agency_staff_can_read_or_save(): void
    {
        $this->getJson('/api/v1/admin/platform-settings', $this->auth($this->clientAdmin))->assertForbidden();
        $this->putJson('/api/v1/admin/platform-settings', ['values' => ['meta_app_id' => 'x']], $this->auth($this->clientAdmin))->assertForbidden();
        $this->getJson('/api/v1/admin/platform-settings', $this->auth($this->staff))
            ->assertOk()
            ->assertJsonPath('data.groups.0.configured', false)
            ->assertJsonPath('data.urls.meta_webhook', url('/api/v1/webhooks/meta'));
    }

    public function test_save_stores_encrypted_secret_and_masks_it(): void
    {
        $this->putJson('/api/v1/admin/platform-settings', ['values' => [
            'meta_app_id' => '1234567890',
            'meta_app_secret' => 'super-secret-value',
            'meta_redirect_uri' => 'https://crm.example.com/api/v1/social/meta/callback',
            'meta_webhook_verify_token' => 'verify-me-123',
        ]], $this->auth($this->staff))->assertOk();

        // Raw DB value must be encrypted, never plaintext.
        $raw = DB::table('platform_settings')->where('key', 'meta_app_secret')->value('value');
        $this->assertNotSame('super-secret-value', $raw);
        $this->assertNotEmpty($raw);

        $res = $this->getJson('/api/v1/admin/platform-settings', $this->auth($this->staff));
        $fields = collect($res->json('data.groups.0.fields'))->keyBy('key');
        $this->assertSame('1234567890', $fields['meta_app_id']['value']);
        $this->assertSame('database', $fields['meta_app_id']['source']);
        $this->assertTrue($fields['meta_app_secret']['is_set']);
        $this->assertNull($fields['meta_app_secret']['value']); // masked
    }

    public function test_database_values_drive_meta_client_and_webhook_verification(): void
    {
        $this->putJson('/api/v1/admin/platform-settings', ['values' => [
            'meta_app_id' => '999',
            'meta_app_secret' => 'shh',
            'meta_redirect_uri' => 'https://crm.example.com/api/v1/social/meta/callback',
            'meta_webhook_verify_token' => 'verify-me-123',
        ]], $this->auth($this->staff))->assertOk();

        // A fresh request boots the app and applies DB settings to config.
        $res = $this->get('/api/v1/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me-123&hub_challenge=challenge-42');
        $res->assertOk()->assertSee('challenge-42');

        $client = app(MetaGraphClient::class);
        $this->assertTrue($client->configured());
        $this->assertSame('999', $client->appId());
    }

    public function test_blank_secret_keeps_existing_and_null_clears(): void
    {
        $this->putJson('/api/v1/admin/platform-settings', ['values' => ['meta_app_secret' => 'keep-me']], $this->auth($this->staff))->assertOk();
        $this->putJson('/api/v1/admin/platform-settings', ['values' => ['meta_app_secret' => '', 'meta_app_id' => '555']], $this->auth($this->staff))->assertOk();
        $this->assertNotNull(PlatformSetting::where('key', 'meta_app_secret')->first());

        $this->putJson('/api/v1/admin/platform-settings', ['values' => ['meta_app_id' => null]], $this->auth($this->staff))->assertOk();
        $this->assertNull(PlatformSetting::where('key', 'meta_app_id')->first());
    }
}
