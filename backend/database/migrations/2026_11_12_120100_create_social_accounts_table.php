<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('social_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('social_platform_id')->constrained()->cascadeOnDelete();
            $t->string('external_id'); // Facebook Page ID or Instagram user ID
            $t->string('username')->nullable();
            $t->string('display_name');
            $t->string('profile_picture_url')->nullable();
            $t->enum('status', ['connected', 'disconnected', 'expired', 'permission_required', 'error', 'pending'])->default('pending');
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_synced_at')->nullable();
            $t->text('last_error')->nullable(); // redacted provider message only, never tokens
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
            // Explicit short name: MySQL identifiers are limited to 64 chars
            // (the auto-generated name for this set is 69).
            $t->unique(['organization_id', 'social_platform_id', 'external_id'], 'social_accounts_org_platform_ext_unique');
            $t->index(['organization_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('social_accounts'); }
};
