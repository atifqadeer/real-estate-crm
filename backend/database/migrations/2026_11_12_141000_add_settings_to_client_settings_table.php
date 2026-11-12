<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('client_settings', function (Blueprint $t) {
            $t->json('settings')->nullable(); // generic per-workspace key/value bag
        });
    }
    public function down(): void {
        Schema::table('client_settings', function (Blueprint $t) { $t->dropColumn('settings'); });
    }
};
