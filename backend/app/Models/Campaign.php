<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'name', 'objective', 'platform', 'external_campaign_id',
        'meta_ad_account_id', 'start_date', 'end_date', 'budget', 'currency', 'status',
        'target_location', 'property_category', 'landing_page_url', 'tracking_params', 'owner_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'budget' => 'decimal:2', 'tracking_params' => 'array'];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function adGroups(): HasMany { return $this->hasMany(CampaignAdGroup::class); }
    public function ads(): HasMany { return $this->hasMany(CampaignAd::class); }
    public function spendRecords(): HasMany { return $this->hasMany(CampaignSpendRecord::class); }
    public function leads(): HasMany { return $this->hasMany(Lead::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }

    public function utmCampaign(): ?string
    {
        return $this->tracking_params['utm_campaign'] ?? null;
    }
}
