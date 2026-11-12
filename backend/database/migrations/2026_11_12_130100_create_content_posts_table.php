<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title')->nullable();
            $t->text('caption')->nullable();
            $t->text('hashtags')->nullable();
            $t->string('link_url')->nullable();
            $t->string('cta_text')->nullable();
            $t->text('first_comment')->nullable();
            $t->text('internal_notes')->nullable();
            $t->enum('status', ['draft', 'pending_approval', 'changes_requested', 'approved', 'rejected',
                'scheduled', 'publishing', 'published', 'partially_published', 'failed', 'cancelled'])->default('draft');
            $t->boolean('approval_required')->default(false);
            $t->timestamp('scheduled_at')->nullable(); // always stored UTC
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'status']);
            $t->index(['organization_id', 'scheduled_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('content_posts'); }
};
