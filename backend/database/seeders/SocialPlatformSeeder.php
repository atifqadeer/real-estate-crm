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
            ['key' => 'linkedin', 'name' => 'LinkedIn Company Pages', 'is_connectable' => true,
             'capabilities' => ['connect' => true, 'discovery' => 'organizationAcls_role_administrator'],
             'notes' => 'OAuth member authorization + REST Posts API. Requires a LinkedIn app with the Community Management API product (Development tier to build/test, Standard tier for production) and the member must be an ADMINISTRATOR of the Company Page.'],
            ['key' => 'tiktok', 'name' => 'TikTok', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Not shipped: the Content Posting API restricts unaudited apps to private-only posts; public posting requires passing TikTok\'s app audit. Revisit after audit approval.'],
            ['key' => 'x', 'name' => 'X', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Not shipped: X API is pay-per-use credit billing (post create $0.015, $0.20 with a URL) — not commercially justified for this product yet.'],
            ['key' => 'youtube', 'name' => 'YouTube', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Not shipped: video-first workflow; uploads draw on a separate 100/day upload quota and quota increases need a YouTube compliance audit. The composer\'s video pipeline targets Reels/TikTok-style distribution first.'],
            ['key' => 'pinterest', 'name' => 'Pinterest', 'is_connectable' => false,
             'capabilities' => [], 'notes' => 'Not shipped: Trial access creates sandbox-only pins invisible to the public; Standard access needs a reviewed demo video. Low demand from Pakistani real-estate clients.'],
        ];
        foreach ($platforms as $p) {
            SocialPlatform::updateOrCreate(['key' => $p['key']], $p);
        }
    }
}
