<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Every source event for a person, preserved on the original lead.
        Schema::create('lead_touchpoints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source_label')->nullable();
            $t->string('campaign_name')->nullable();
            $t->string('meta_lead_id')->nullable();
            $t->json('utm')->nullable();
            $t->json('payload')->nullable(); // raw source data (e.g. Meta field_data)
            $t->timestamp('created_at')->useCurrent();
            $t->index(['lead_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('lead_touchpoints'); }
};
