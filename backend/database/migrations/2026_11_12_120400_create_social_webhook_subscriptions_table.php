<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('social_webhook_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $t->string('object_type'); // e.g. page, instagram
            $t->json('fields')->nullable();
            $t->enum('status', ['active', 'inactive', 'error'])->default('inactive');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('social_webhook_subscriptions'); }
};
