<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('email');
            $t->string('role');
            $t->string('token_hash', 64);
            $t->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'email']);
        });
    }
    public function down(): void { Schema::dropIfExists('invitations'); }
};
