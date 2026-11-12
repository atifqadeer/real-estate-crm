<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider'); // meta
            $t->string('event_key'); // leadgen_id
            $t->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $t->json('payload');
            $t->enum('status', ['received', 'processed', 'failed', 'ignored'])->default('received');
            $t->text('error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_key']);
        });
    }
    public function down(): void { Schema::dropIfExists('webhook_events'); }
};
