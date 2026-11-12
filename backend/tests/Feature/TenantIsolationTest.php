<?php
namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 1 acceptance gate (prompt §23): tenant isolation.
 * Client A must never reach Client B's data; roles are per-organization.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;
    private Organization $orgB;
    private User $ownerA;
    private User $ownerB;
    private User $multi;
    private User $agencyStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $roles = app(RoleProvisioner::class);

        $this->orgA = Organization::factory()->create(['business_name' => 'Client A Estates']);
        $this->orgB = Organization::factory()->create(['business_name' => 'Client B Estates']);
        foreach ([$this->orgA, $this->orgB] as $org) {
            $org->settings()->create([]);
            $roles->provisionFor($org);
        }

        $this->ownerA = User::factory()->create();
        $this->join($this->orgA, $this->ownerA, 'client_owner');
        $this->ownerB = User::factory()->create();
        $this->join($this->orgB, $this->ownerB, 'client_owner');

        // Same person, different role per workspace.
        $this->multi = User::factory()->create();
        $this->join($this->orgA, $this->multi, 'client_sales_agent');
        $this->join($this->orgB, $this->multi, 'client_admin');

        // Agency super admin with NO membership on Client B.
        $agency = Organization::factory()->agency()->create();
        $agency->settings()->create([]);
        $roles->provisionFor($agency);
        $this->agencyStaff = User::factory()->create(['is_agency_staff' => true]);
        $this->join($agency, $this->agencyStaff, 'super_admin');
    }

    private function join(Organization $org, User $user, string $role): void
    {
        $org->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
        app(RoleProvisioner::class)->assign($org, $user, $role);
    }

    public function test_client_a_cannot_view_client_b_organization(): void
    {
        Sanctum::actingAs($this->ownerA);
        $this->getJson("/api/v1/organizations/{$this->orgB->id}")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$this->orgA->id}")->assertOk();
    }

    public function test_client_a_cannot_read_client_b_settings_members_dashboard_or_audit(): void
    {
        Sanctum::actingAs($this->ownerA);
        $b = $this->orgB->id;
        $this->getJson("/api/v1/organizations/{$b}/settings")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$b}/members")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$b}/dashboard")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$b}/audit-logs")->assertForbidden();
    }

    public function test_agency_staff_without_membership_cannot_access_client_b(): void
    {
        Sanctum::actingAs($this->agencyStaff);
        $this->getJson("/api/v1/organizations/{$this->orgB->id}")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$this->orgB->id}/dashboard")->assertForbidden();
    }

    public function test_roles_are_per_organization(): void
    {
        Sanctum::actingAs($this->multi);
        // Sales agent in A: no settings.update.
        $this->putJson("/api/v1/organizations/{$this->orgA->id}/settings", [
            'lead_assignment_mode' => 'round_robin',
        ])->assertForbidden();
        // Client admin in B: allowed.
        $this->putJson("/api/v1/organizations/{$this->orgB->id}/settings", [
            'lead_assignment_mode' => 'round_robin',
        ])->assertOk()->assertJsonPath('data.lead_assignment_mode', 'round_robin');
    }

    public function test_settings_update_writes_audit_log(): void
    {
        Sanctum::actingAs($this->ownerB);
        $this->putJson("/api/v1/organizations/{$this->orgB->id}/settings", [
            'lead_assignment_mode' => 'location',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->orgB->id,
            'user_id' => $this->ownerB->id,
            'action' => 'settings.updated',
        ]);
    }

    public function test_me_returns_correct_roles_per_organization(): void
    {
        Sanctum::actingAs($this->multi);
        $res = $this->getJson('/api/v1/auth/me')->assertOk();
        $byOrg = collect($res->json('data.memberships'))->keyBy('organization_id');
        $this->assertSame(['client_sales_agent'], $byOrg[$this->orgA->id]['roles']);
        $this->assertSame(['client_admin'], $byOrg[$this->orgB->id]['roles']);
    }

    public function test_dashboard_is_tenant_scoped(): void
    {
        Sanctum::actingAs($this->ownerA);
        $this->getJson("/api/v1/organizations/{$this->orgA->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.organization.id', $this->orgA->id)
            ->assertJsonPath('data.members_count', 2); // ownerA + multi
    }

    public function test_invitation_accept_grants_access_only_to_that_workspace(): void
    {
        Sanctum::actingAs($this->ownerB);
        $res = $this->postJson("/api/v1/organizations/{$this->orgB->id}/invitations", [
            'email' => 'newagent@demo.pk', 'role' => 'client_sales_agent',
        ])->assertCreated();
        $token = $res->json('data.invite_token');
        $this->assertNotEmpty($token);

        $accept = $this->postJson("/api/v1/invitations/{$token}/accept", [
            'name' => 'New Agent', 'password' => 'secret-password',
        ])->assertOk();
        $newUser = User::where('email', 'newagent@demo.pk')->firstOrFail();

        Sanctum::actingAs($newUser);
        $this->getJson("/api/v1/organizations/{$this->orgB->id}/dashboard")->assertOk();
        $this->getJson("/api/v1/organizations/{$this->orgA->id}/dashboard")->assertForbidden();
    }

    public function test_cross_tenant_member_update_is_forbidden(): void
    {
        Sanctum::actingAs($this->ownerA);
        $this->putJson("/api/v1/organizations/{$this->orgB->id}/members/{$this->ownerB->id}", [
            'role' => 'client_readonly',
        ])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson("/api/v1/organizations/{$this->orgA->id}")->assertUnauthorized();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
