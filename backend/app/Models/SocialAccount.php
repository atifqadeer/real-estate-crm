<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'organization_id', 'social_platform_id', 'external_id', 'username', 'display_name',
        'profile_picture_url', 'status', 'connected_by', 'last_synced_at', 'last_error', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function platform(): BelongsTo { return $this->belongsTo(SocialPlatform::class, 'social_platform_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function token(): HasOne { return $this->hasOne(SocialAccountToken::class); }
    public function permissions(): HasMany { return $this->hasMany(SocialAccountPermission::class); }
    public function logs(): HasMany { return $this->hasMany(SocialAccountLog::class); }
    public function connectedBy(): BelongsTo { return $this->belongsTo(User::class, 'connected_by'); }

    public function grantedPermissions(): array
    {
        return $this->permissions()->where('status', 'granted')->pluck('permission')->all();
    }
}
