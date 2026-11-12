<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('objective')->nullable(); // leads, traffic, engagement, sales, awareness
            $t->string('platform')->default('meta'); // meta, google, tiktok, offline, other
            $t->string('external_campaign_id')->nullable();
            $t->string('meta_ad_account_id')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->decimal('budget', 14, 2)->nullable();
            $t->string('currency', 3)->default('PKR');
            $t->enum('status', ['draft', 'active', 'paused', 'completed'])->default('draft');
            $t->string('target_location')->nullable();
            $t->string('property_category')->nullable();
            $t->string('landing_page_url')->nullable();
            $t->json('tracking_params')->nullable(); // {utm_source, utm_medium, utm_campaign}
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'external_campaign_id']);
        });
        Schema::create('campaign_ad_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('external_id')->nullable();
            $t->timestamps();
        });
        Schema::create('campaign_ads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_ad_group_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('external_id')->nullable();
            $t->string('creative_reference')->nullable(); // headline / asset note / URL
            $t->timestamps();
        });
        Schema::create('campaign_spend_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->decimal('spend', 14, 2)->default(0);
            // Provider metrics stay NULL when the provider/manual entry does not supply them
            // (missing data is not zero — prompt section 16).
            $t->unsignedBigInteger('impressions')->nullable();
            $t->unsignedBigInteger('reach')->nullable();
            $t->unsignedBigInteger('clicks')->nullable();
            $t->unsignedBigInteger('conversions')->nullable();
            $t->enum('source', ['manual', 'meta_api'])->default('manual');
            $t->enum('level', ['campaign', 'adset', 'ad'])->default('campaign');
            $t->string('external_id')->nullable(); // campaign/adset/ad id at this level
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['campaign_id', 'date', 'level', 'external_id'], 'spend_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('campaign_spend_records');
        Schema::dropIfExists('campaign_ads');
        Schema::dropIfExists('campaign_ad_groups');
        Schema::dropIfExists('campaigns');
    }
};
