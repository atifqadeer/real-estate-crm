<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->enum('type', ['agency', 'real_estate_client']);
            $t->string('business_name');
            $t->string('legal_name')->nullable();
            $t->string('contact_person');
            $t->string('email');
            $t->string('phone_e164', 20)->nullable();
            $t->string('country')->default('Pakistan');
            $t->string('city')->nullable();
            $t->string('address')->nullable();
            $t->string('timezone')->default('Asia/Karachi');
            $t->string('currency', 3)->default('PKR');
            $t->string('logo_path')->nullable();
            $t->json('branding')->nullable();
            $t->enum('status', ['active', 'suspended', 'archived'])->default('active');
            $t->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['type', 'status']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone_e164', 20)->nullable();
            $t->boolean('is_agency_staff')->default(false);
            $t->timestamp('last_login_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['phone_e164', 'is_agency_staff', 'last_login_at']));
        Schema::dropIfExists('organizations');
    }
};
