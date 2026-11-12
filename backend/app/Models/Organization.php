<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type', 'business_name', 'legal_name', 'contact_person', 'email', 'phone_e164',
        'country', 'city', 'address', 'timezone', 'currency', 'logo_path', 'branding',
        'status', 'account_manager_id',
    ];

    protected function casts(): array
    {
        return ['branding' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot(['status', 'joined_at'])->withTimestamps();
    }

    public function settings(): HasOne { return $this->hasOne(ClientSetting::class); }
    public function invitations(): HasMany { return $this->hasMany(Invitation::class); }
    public function auditLogs(): HasMany { return $this->hasMany(AuditLog::class); }
    public function subscriptions(): HasMany { return $this->hasMany(ClientSubscription::class); }
    public function isAgency(): bool { return $this->type === 'agency'; }
}
