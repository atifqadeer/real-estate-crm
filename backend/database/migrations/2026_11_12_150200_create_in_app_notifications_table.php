<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('in_app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type'); // lead.assigned, followup.due, content.published, whatsapp.message, ...
            $t->string('title');
            $t->text('body')->nullable();
            $t->json('data')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });
        Schema::create('user_notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->json('disabled_types')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('in_app_notifications');
    }
};
