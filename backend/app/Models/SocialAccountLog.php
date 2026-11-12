<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccountLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'social_account_id', 'user_id', 'event', 'message', 'metadata'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }

    public function account(): BelongsTo { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
