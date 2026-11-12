<?php
namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\RoleProvisioner;
use Illuminate\Database\Seeder;

/** DEMO DATA for local development only. Clearly fake; never production data. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $roles = app(RoleProvisioner::class);
        $roles->ensurePermissions();

        $agency = Organization::firstOrCreate(
            ['email' => 'hello@agency.demo'],
            ['type' => 'agency', 'business_name' => 'DEMO Marketing Agency', 'contact_person' => 'Agency Admin', 'city' => 'Karachi']
        );
        $agency->settings()->firstOrCreate([]);
        $roles->provisionFor($agency);

        $admin = User::firstOrCreate(['email' => 'admin@agency.demo'], ['name' => 'DEMO Agency Admin', 'password' => 'password', 'is_agency_staff' => true]);
        $agency->users()->syncWithoutDetaching([$admin->id => ['status' => 'active', 'joined_at' => now()]]);
        $roles->assign($agency, $admin, 'super_admin');

        foreach (['A' => 'owner@clienta.demo', 'B' => 'owner@clientb.demo'] as $label => $email) {
            $client = Organization::firstOrCreate(
                ['email' => "hello@client{$label}.demo"],
                ['type' => 'real_estate_client', 'business_name' => "DEMO Client {$label} Estates", 'contact_person' => "Owner {$label}", 'city' => $label === 'A' ? 'Karachi' : 'Lahore']
            );
            $client->settings()->firstOrCreate([]);
            $roles->provisionFor($client);
            $owner = User::firstOrCreate(['email' => $email], ['name' => "DEMO Owner {$label}", 'password' => 'password']);
            $client->users()->syncWithoutDetaching([$owner->id => ['status' => 'active', 'joined_at' => now()]]);
            $roles->assign($client, $owner, 'client_owner');
        }

        // One person, different roles in different workspaces (Phase 1 proof case).
        $clientA = Organization::where('email', 'hello@clientA.demo')->first();
        $clientB = Organization::where('email', 'hello@clientB.demo')->first();
        $multi = User::firstOrCreate(['email' => 'multi@demo.pk'], ['name' => 'DEMO Multi Org User', 'password' => 'password']);
        $clientA->users()->syncWithoutDetaching([$multi->id => ['status' => 'active', 'joined_at' => now()]]);
        $roles->assign($clientA, $multi, 'client_sales_agent');
        $clientB->users()->syncWithoutDetaching([$multi->id => ['status' => 'active', 'joined_at' => now()]]);
        $roles->assign($clientB, $multi, 'client_admin');
    }
}
