<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_saved_filters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('criteria'); // {search, stage_id, source_id, assigned_to, possible_duplicates}
            $t->boolean('is_shared')->default(false);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('lead_saved_filters'); }
};
