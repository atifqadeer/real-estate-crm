<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('social_account_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $t->string('permission');
            $t->enum('status', ['granted', 'declined'])->default('granted');
            $t->timestamps();
            $t->unique(['social_account_id', 'permission']);
        });
    }
    public function down(): void { Schema::dropIfExists('social_account_permissions'); }
};
