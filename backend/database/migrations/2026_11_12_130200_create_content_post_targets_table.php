<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_post_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $t->string('platform'); // denormalized: facebook | instagram
            $t->text('caption_override')->nullable();
            $t->text('hashtags_override')->nullable();
            $t->enum('status', ['pending', 'scheduled', 'queued', 'publishing', 'published', 'failed', 'cancelled'])->default('pending');
            $t->string('platform_post_id')->nullable();
            $t->string('platform_url')->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->json('provider_response')->nullable(); // redacted provider payloads + progress (e.g. IG container id)
            $t->string('idempotency_key')->unique();
            $t->timestamps();
            $t->unique(['content_post_id', 'social_account_id']);
            $t->index(['status', 'next_retry_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('content_post_targets'); }
};
