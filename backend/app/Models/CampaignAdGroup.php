<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CampaignAdGroup extends Model
{
    use BelongsToTenant;
    protected $fillable = ['organization_id', 'campaign_id', 'name', 'external_id'];
}
