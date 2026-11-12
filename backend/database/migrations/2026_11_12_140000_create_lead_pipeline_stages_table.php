<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_pipeline_stages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_won')->default(false);
            $t->boolean('is_lost')->default(false);
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
        });
    }
    public function down(): void { Schema::dropIfExists('lead_pipeline_stages'); }
};
