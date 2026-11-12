<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CampaignSpendRecord extends Model
{
    use BelongsToTenant;
    protected $fillable = ['organization_id', 'campaign_id', 'date', 'spend', 'impressions', 'reach',
        'clicks', 'conversions', 'source', 'level', 'external_id', 'created_by'];
    protected function casts(): array { return ['date' => 'date', 'spend' => 'decimal:2']; }
}
