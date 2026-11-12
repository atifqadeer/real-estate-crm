<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('social_platforms', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique(); // facebook, instagram, linkedin, tiktok, x, youtube, pinterest
            $t->string('name');
            $t->boolean('is_connectable')->default(false); // true only when a real adapter ships
            $t->json('capabilities')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('social_platforms'); }
};
