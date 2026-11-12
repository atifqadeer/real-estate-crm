<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('social_account_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event'); // connected, disconnected, test_ok, test_failed, token_refreshed, error, oauth_denied
            $t->text('message')->nullable(); // redacted
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['organization_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('social_account_logs'); }
};
