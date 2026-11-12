<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialWebhookSubscription extends Model
{
    protected $fillable = ['social_account_id', 'object_type', 'fields', 'status'];
    protected function casts(): array { return ['fields' => 'array']; }
    public function account(): BelongsTo { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
}
