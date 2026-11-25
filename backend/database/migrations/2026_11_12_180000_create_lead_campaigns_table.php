<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('vertical')->default('real_estate'); // preset key or 'custom'
            $t->string('vertical_custom')->nullable();
            $t->json('keywords')->nullable();   // enquiry text is matched against these
            $t->json('locations')->nullable();  // optional city/area filter
            $t->json('sources')->nullable();    // source keys this campaign listens to (empty = all)
            $t->text('description')->nullable();
            $t->string('webhook_key', 64)->unique(); // public lead webhook (Google Ads, Zapier, ...)
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'is_active']);
        });

        Schema::table('leads', function (Blueprint $t) {
            $t->foreignId('lead_campaign_id')->nullable()->constrained('lead_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lead_campaign_id');
        });
        Schema::dropIfExists('lead_campaigns');
    }
};
