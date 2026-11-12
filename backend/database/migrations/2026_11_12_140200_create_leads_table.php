<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('full_name');
            $t->string('phone_e164')->nullable();
            $t->string('whatsapp_e164')->nullable();
            $t->string('email')->nullable();
            $t->string('city')->nullable();
            $t->string('area')->nullable();
            $t->string('property_type')->nullable();
            $t->string('property_reference')->nullable();
            $t->enum('intent', ['buy', 'sell', 'rent', 'invest'])->nullable();
            $t->string('preferred_location')->nullable();
            $t->decimal('budget_min', 15, 2)->nullable();
            $t->decimal('budget_max', 15, 2)->nullable();
            $t->string('budget_currency', 3)->default('PKR');
            $t->unsignedTinyInteger('bedrooms')->nullable();
            $t->unsignedTinyInteger('bathrooms')->nullable();
            $t->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('campaign_name')->nullable();
            $t->string('meta_lead_id')->nullable();
            $t->string('meta_form_id')->nullable();
            $t->string('meta_page_id')->nullable();
            $t->string('meta_campaign_id')->nullable();
            $t->string('meta_ad_id')->nullable();
            $t->string('meta_adgroup_id')->nullable();
            $t->string('ad_name')->nullable();
            $t->string('utm_source')->nullable();
            $t->string('utm_medium')->nullable();
            $t->string('utm_campaign')->nullable();
            $t->string('utm_term')->nullable();
            $t->string('utm_content')->nullable();
            $t->string('landing_page')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('lead_pipeline_stage_id')->nullable()->constrained()->nullOnDelete();
            $t->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $t->boolean('consent_marketing')->default(false);
            $t->boolean('consent_whatsapp')->default(false);
            $t->text('notes')->nullable();
            $t->json('custom_fields')->nullable();
            $t->foreignId('possible_duplicate_of')->nullable()->constrained('leads')->nullOnDelete();
            $t->timestamp('last_contacted_at')->nullable();
            $t->timestamp('next_follow_up_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'meta_lead_id']);
            $t->index(['organization_id', 'phone_e164']);
            $t->index(['organization_id', 'email']);
            $t->index(['organization_id', 'assigned_to']);
        });
    }
    public function down(): void { Schema::dropIfExists('leads'); }
};
