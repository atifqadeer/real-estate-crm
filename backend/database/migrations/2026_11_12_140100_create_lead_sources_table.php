<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('key'); // manual, csv_import, website_form, meta_lead_ads, ...
            $t->string('name');
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['organization_id', 'key']);
        });
    }
    public function down(): void { Schema::dropIfExists('lead_sources'); }
};
