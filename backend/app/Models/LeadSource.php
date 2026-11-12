<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'key', 'name', 'is_system'];
    protected function casts(): array { return ['is_system' => 'boolean']; }

    public static function systemSources(): array
    {
        return [
            ['key' => 'manual', 'name' => 'Manual entry'],
            ['key' => 'csv_import', 'name' => 'CSV/XLSX import'],
            ['key' => 'website_form', 'name' => 'Website form'],
            ['key' => 'meta_lead_ads', 'name' => 'Meta Lead Ads'],
            ['key' => 'whatsapp', 'name' => 'WhatsApp'],
            ['key' => 'walk_in', 'name' => 'Walk-in / Office visit'],
            ['key' => 'referral', 'name' => 'Referral'],
        ];
    }
}
