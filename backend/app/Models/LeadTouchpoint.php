<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTouchpoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'lead_id', 'lead_source_id', 'source_label', 'campaign_name', 'meta_lead_id', 'utm', 'payload'];
    protected function casts(): array { return ['utm' => 'array', 'payload' => 'array', 'created_at' => 'datetime']; }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function source(): BelongsTo { return $this->belongsTo(LeadSource::class, 'lead_source_id'); }
}
