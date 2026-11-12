<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialOauthIdentity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'organization_id', 'provider', 'external_user_id', 'name',
        'access_token', 'token_expires_at', 'scopes', 'connected_by',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
}
