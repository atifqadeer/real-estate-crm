<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'client_subscription_id', 'service_package_id', 'number',
        'amount', 'currency', 'status', 'issued_date', 'due_date', 'paid_at', 'payment_method', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'issued_date' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime'];
    }

    public function subscription(): BelongsTo { return $this->belongsTo(ClientSubscription::class, 'client_subscription_id'); }
    public function package(): BelongsTo { return $this->belongsTo(ServicePackage::class, 'service_package_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
}
