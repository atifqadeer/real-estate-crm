<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->enum('status', ['pending', 'done', 'cancelled'])->default('pending');
            $t->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'assigned_to', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('lead_tasks'); }
};
