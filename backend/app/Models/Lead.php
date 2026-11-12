<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'organization_id', 'full_name', 'phone_e164', 'whatsapp_e164', 'email', 'city', 'area',
        'property_type', 'property_reference', 'intent', 'preferred_location',
        'budget_min', 'budget_max', 'budget_currency', 'bedrooms', 'bathrooms',
        'lead_source_id', 'campaign_name', 'meta_lead_id', 'meta_form_id', 'meta_page_id',
        'meta_campaign_id', 'meta_ad_id', 'meta_adgroup_id', 'ad_name',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page',
        'assigned_to', 'lead_pipeline_stage_id', 'priority', 'consent_marketing', 'consent_whatsapp',
        'notes', 'custom_fields', 'possible_duplicate_of', 'last_contacted_at', 'next_follow_up_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'consent_marketing' => 'boolean',
            'consent_whatsapp' => 'boolean',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'last_contacted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function source(): BelongsTo { return $this->belongsTo(LeadSource::class, 'lead_source_id'); }
    public function stage(): BelongsTo { return $this->belongsTo(LeadPipelineStage::class, 'lead_pipeline_stage_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function touchpoints(): HasMany { return $this->hasMany(LeadTouchpoint::class); }
    public function activities(): HasMany { return $this->hasMany(LeadActivity::class); }
    public function followUps(): HasMany { return $this->hasMany(LeadFollowUp::class); }
    public function possibleDuplicateOf(): BelongsTo { return $this->belongsTo(Lead::class, 'possible_duplicate_of'); }
}
