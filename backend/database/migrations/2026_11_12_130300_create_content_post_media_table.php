<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('content_post_media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['content_post_id', 'media_asset_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('content_post_media'); }
};
