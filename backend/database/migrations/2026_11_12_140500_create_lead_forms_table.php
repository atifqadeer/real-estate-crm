<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('public_key', 64)->unique();
            $t->string('property_reference')->nullable();
            $t->enum('intent', ['buy', 'sell', 'rent', 'invest'])->nullable();
            $t->string('success_message')->default('Thank you! Our team will contact you shortly.');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('submissions_count')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('lead_forms'); }
};
