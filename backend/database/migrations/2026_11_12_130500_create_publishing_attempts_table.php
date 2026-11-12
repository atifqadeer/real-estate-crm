<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('publishing_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_post_target_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('attempt_no');
            $t->enum('status', ['started', 'succeeded', 'failed']);
            $t->json('provider_request')->nullable(); // endpoint + params, token NEVER included
            $t->json('provider_response')->nullable();
            $t->text('error_message')->nullable();
            $t->boolean('permanent')->default(false);
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('finished_at')->nullable();
            $t->index(['content_post_target_id', 'attempt_no']);
        });
    }
    public function down(): void { Schema::dropIfExists('publishing_attempts'); }
};
