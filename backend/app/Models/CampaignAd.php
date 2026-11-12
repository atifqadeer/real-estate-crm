<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CampaignAd extends Model
{
    use BelongsToTenant;
    protected $fillable = ['organization_id', 'campaign_id', 'campaign_ad_group_id', 'name', 'external_id', 'creative_reference'];
}
