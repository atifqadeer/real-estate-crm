<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('social_account_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('social_account_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('token_type')->default('page_access_token');
            $t->text('access_token'); // ALWAYS stored via Laravel 'encrypted' cast
            $t->text('refresh_token')->nullable(); // encrypted cast; Meta user tokens refresh by re-exchange
            $t->timestamp('token_expires_at')->nullable(); // null = provider reports no expiry (e.g. Page token from long-lived user token)
            $t->json('scopes')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('social_account_tokens'); }
};
