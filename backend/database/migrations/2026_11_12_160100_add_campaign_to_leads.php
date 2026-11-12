<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('leads', function (Blueprint $t) {
            $t->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $t->string('attribution_method')->nullable(); // manual, meta_id, utm, touchpoint
        });
    }
    public function down(): void {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropConstrainedForeignId('campaign_id');
            $t->dropColumn('attribution_method');
        });
    }
};
