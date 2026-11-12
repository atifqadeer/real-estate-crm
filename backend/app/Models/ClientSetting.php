<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'organization_id', 'default_lead_pipeline', 'approval_rules',
        'notification_settings', 'lead_assignment_mode', 'data_retention_days', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'default_lead_pipeline' => 'array',
            'approval_rules' => 'array',
            'notification_settings' => 'array',
            'settings' => 'array',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
}
