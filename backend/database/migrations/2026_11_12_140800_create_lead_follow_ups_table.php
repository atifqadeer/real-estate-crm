<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_follow_ups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who must follow up
            $t->timestamp('due_at');
            $t->string('note')->nullable();
            $t->enum('status', ['pending', 'done', 'cancelled'])->default('pending');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status', 'due_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('lead_follow_ups'); }
};
