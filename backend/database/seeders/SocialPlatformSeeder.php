<?php
namespace Database\Seeders;

use App\Models\SocialPlatform;
use Illuminate\Database\Seeder;

class SocialPlatformSeeder extends Seeder
{
    public function run(): void
    {
        // Only platforms with a real shipped adapter are connectable.
        // Others are registered as planned with their gating reason (prompt §6/§24:
        // never present an unimplemented integration as available).
        $platforms = [
            ['key' => 'facebook', 'name' => 'Facebook Pages', 'is_connectable' => true,
             'capabilities' => ['connect' => true, 'discovery' => 'pages_via_me_accounts'],
             'notes' => 'OAuth via Facebook Login. Requires Meta app credentials + Advanced Access (App Review + Business Verification) to serve client Pages.'],
            ['key' => 'instagram', 'name' => 'Instagram Professional', 'is_connectable' => true,
             'capabilities' => ['connect' => true, 'discovery' => 'via_linked_facebook_page'],
             'notes' => 'Instagram API with Facebook Login: professional (Business/Creator) account linked to a Facebook Page. Personal accounts cannot connect.'],
            ['key' => 'linkedin', 'name' => 'LinkedIn Company Pages', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Phase 7. Requires LinkedIn Marketing Developer Platform approval.'],
            ['key' => 'tiktok', 'name' => 'TikTok', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Phase 7. Requires TikTok app approval and account eligibility checks.'],
            ['key' => 'x', 'name' => 'X', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Phase 7, only if commercially justified.'],
            ['key' => 'youtube', 'name' => 'YouTube', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Phase 7, only if commercially justified.'],
            ['key' => 'pinterest', 'name' => 'Pinterest', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Phase 7, only if commercially justified.'],
        ];
        foreach ($platforms as $p) {
            SocialPlatform::updateOrCreate(['key' => $p['key']], $p);
        }
    }
}
