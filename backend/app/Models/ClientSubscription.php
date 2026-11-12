<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSubscription extends Model
{
    protected $fillable = ['organization_id', 'service_package_id', 'status', 'starts_at', 'ends_at'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function package(): BelongsTo { return $this->belongsTo(ServicePackage::class, 'service_package_id'); }
}
