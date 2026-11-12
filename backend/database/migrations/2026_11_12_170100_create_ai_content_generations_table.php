<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_content_generations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('platform')->nullable();
            $t->string('language', 8)->default('en');
            $t->string('model')->nullable();
            $t->json('inputs');
            $t->json('suggestions');
            $t->timestamp('created_at')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('ai_content_generations'); }
};
