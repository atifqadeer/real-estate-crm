<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('client_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $t->json('default_lead_pipeline')->nullable();
            $t->json('approval_rules')->nullable();
            $t->json('notification_settings')->nullable();
            $t->enum('lead_assignment_mode', ['manual', 'round_robin', 'location', 'category'])->default('manual');
            $t->unsignedInteger('data_retention_days')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('client_settings'); }
};
