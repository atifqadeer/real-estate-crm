<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // The person who connected accounts (e.g. a client's Page admin).
        // Their long-lived user token is what Page tokens are derived from,
        // so it is stored (encrypted) to refresh and re-sync accounts.
        Schema::create('social_oauth_identities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('provider')->default('meta');
            $t->string('external_user_id');
            $t->string('name')->nullable();
            $t->text('access_token'); // encrypted cast
            $t->timestamp('token_expires_at')->nullable();
            $t->json('scopes')->nullable();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            // Explicit short name: MySQL's 64-char identifier limit (auto name is 72).
            $t->unique(['organization_id', 'provider', 'external_user_id'], 'social_oauth_identities_org_provider_user_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('social_oauth_identities'); }
};
