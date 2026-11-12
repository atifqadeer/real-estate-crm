<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_assignment_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->enum('match_type', ['city', 'property_type', 'source', 'default']);
            $t->string('match_value')->nullable();
            $t->foreignId('assign_to_user_id')->constrained('users')->cascadeOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('lead_assignment_rules'); }
};
