<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_post_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('content_post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('action', ['submitted', 'approved', 'rejected', 'changes_requested']);
            $t->text('comment')->nullable(); // required for rejected / changes_requested (validated in service)
            $t->timestamp('created_at')->useCurrent();
            $t->index(['content_post_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('content_post_approvals'); }
};
