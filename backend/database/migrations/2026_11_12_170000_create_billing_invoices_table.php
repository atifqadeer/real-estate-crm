<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete(); // client org being billed
            $t->foreignId('client_subscription_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete();
            $t->string('number');
            $t->decimal('amount', 15, 2);
            $t->string('currency', 3)->default('PKR');
            $t->enum('status', ['draft', 'sent', 'paid', 'overdue', 'void'])->default('draft');
            $t->date('issued_date');
            $t->date('due_date')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_method')->nullable(); // bank_transfer, jazzcash, easypaisa, cash, card, other
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
        });
    }
    public function down(): void { Schema::dropIfExists('billing_invoices'); }
};
