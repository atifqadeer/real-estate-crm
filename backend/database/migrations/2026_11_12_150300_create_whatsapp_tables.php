<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('whatsapp_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('waba_id');
            $t->string('phone_number_id')->unique();
            $t->string('display_phone_number')->nullable();
            $t->string('verified_name')->nullable();
            $t->string('quality_rating')->nullable();
            $t->string('messaging_limit')->nullable();
            $t->text('access_token'); // encrypted cast
            $t->enum('status', ['connected', 'disconnected', 'error'])->default('connected');
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('whatsapp_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('language', 16);
            $t->string('category')->nullable(); // MARKETING / UTILITY / AUTHENTICATION
            $t->string('status')->nullable();   // APPROVED / PENDING / REJECTED
            $t->json('components')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['whatsapp_account_id', 'name', 'language']);
        });
        Schema::create('whatsapp_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contact_phone_e164');
            $t->string('contact_name')->nullable();
            $t->enum('status', ['open', 'closed'])->default('open');
            $t->unsignedInteger('unread_count')->default(0);
            $t->timestamp('window_expires_at')->nullable(); // 24h customer service window
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('opted_out_at')->nullable();
            $t->timestamps();
            $t->unique(['whatsapp_account_id', 'contact_phone_e164']);
        });
        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $t->enum('direction', ['in', 'out']);
            $t->string('wa_message_id')->nullable()->unique();
            $t->string('type')->default('text'); // text, template, image, document, audio, video, other
            $t->text('body')->nullable();
            $t->string('media_url')->nullable();
            $t->string('template_name')->nullable();
            $t->enum('status', ['received', 'sent', 'delivered', 'read', 'failed'])->default('sent');
            $t->text('error')->nullable();
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('raw')->nullable();
            $t->timestamps();
            $t->index(['whatsapp_conversation_id', 'created_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
