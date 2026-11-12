<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('media_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('disk')->default('public');
            $t->string('path');
            $t->string('public_url')->nullable(); // URL Meta can fetch; production: S3/CDN
            $t->string('original_name');
            $t->string('mime_type');
            $t->string('extension', 10);
            $t->unsignedBigInteger('size_bytes');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->enum('kind', ['image', 'video']);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['organization_id', 'kind']);
        });
    }
    public function down(): void { Schema::dropIfExists('media_assets'); }
};
