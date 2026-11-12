<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_key', 'organization_id', 'social_account_id', 'payload', 'status', 'error', 'processed_at'];
    protected function casts(): array { return ['payload' => 'array', 'processed_at' => 'datetime']; }

    public function account(): BelongsTo { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
}
