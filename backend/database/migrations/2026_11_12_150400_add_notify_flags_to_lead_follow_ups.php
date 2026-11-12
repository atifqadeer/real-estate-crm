<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('lead_follow_ups', function (Blueprint $t) {
            $t->timestamp('due_notified_at')->nullable();
            $t->timestamp('overdue_notified_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('lead_follow_ups', function (Blueprint $t) {
            $t->dropColumn(['due_notified_at', 'overdue_notified_at']);
        });
    }
};
