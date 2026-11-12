<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('service_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete(); // agency org
            $t->string('name');
            $t->decimal('price', 15, 2)->default(0);
            $t->string('currency', 3)->default('PKR');
            $t->json('limits')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('client_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete(); // client org
            $t->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $t->enum('status', ['trial', 'active', 'past_due', 'cancelled'])->default('trial');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('client_subscriptions');
        Schema::dropIfExists('service_packages');
    }
};
