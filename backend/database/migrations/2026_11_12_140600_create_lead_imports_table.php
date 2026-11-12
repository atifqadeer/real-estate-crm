<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lead_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('filename');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('touchpoint_count')->default(0);
            $t->unsignedInteger('possible_duplicate_count')->default(0);
            $t->unsignedInteger('invalid_count')->default(0);
            $t->json('row_errors')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('lead_imports'); }
};
