<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tokens are encrypted at rest via Laravel encrypted casts and are NEVER
 * exposed through API resources (see SocialAccountResource) or logs.
 */
class SocialAccountToken extends Model
{
    protected $fillable = [
        'social_account_id', 'token_type', 'access_token', 'refresh_token', 'token_expires_at', 'scopes',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
}
