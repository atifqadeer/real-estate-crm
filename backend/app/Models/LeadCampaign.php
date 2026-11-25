<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A lead-generation campaign profile (Phase 8): the workspace says what
 * business it is in (vertical), which keywords mark a relevant enquiry,
 * and which sources to listen to. Incoming leads from any channel are
 * tagged to the first matching active campaign. Deliberately separate
 * from the Phase 6 ad Campaign model (spend/metrics tracking).
 */
class LeadCampaign extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'vertical', 'vertical_custom', 'keywords',
        'locations', 'sources', 'description', 'webhook_key', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'locations' => 'array',
            'sources' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function leads(): HasMany { return $this->hasMany(Lead::class, 'lead_campaign_id'); }

    public function verticalLabel(): string
    {
        if ($this->vertical === 'custom') {
            return $this->vertical_custom ?: 'Custom';
        }
        foreach (self::verticalPresets() as $preset) {
            if ($preset['key'] === $this->vertical) return $preset['label'];
        }
        return $this->vertical;
    }

    /** Industry presets with starter keywords; 'custom' is a blank slate. */
    public static function verticalPresets(): array
    {
        return [
            ['key' => 'real_estate', 'label' => 'Real Estate', 'keywords' => [
                'plot', 'flat', 'apartment', 'house', 'marla', 'kanal', 'booking',
                'installment', 'dha', 'bahria', 'residential', 'commercial', 'rent', 'for sale',
            ]],
            ['key' => 'automobile', 'label' => 'Automobile', 'keywords' => [
                'car', 'vehicle', 'suv', 'sedan', 'hatchback', 'test drive', 'booking',
                'installment', 'price', 'model', 'toyota', 'honda', 'suzuki', 'kia', 'hyundai',
            ]],
            ['key' => 'healthcare', 'label' => 'Healthcare', 'keywords' => [
                'appointment', 'doctor', 'clinic', 'checkup', 'treatment', 'patient', 'consultation',
            ]],
            ['key' => 'education', 'label' => 'Education', 'keywords' => [
                'admission', 'course', 'classes', 'tuition', 'enrollment', 'training', 'certificate',
            ]],
            ['key' => 'retail_ecommerce', 'label' => 'Retail & E-commerce', 'keywords' => [
                'order', 'price', 'discount', 'delivery', 'buy', 'product', 'offer', 'cod',
            ]],
            ['key' => 'home_services', 'label' => 'Home Services', 'keywords' => [
                'plumber', 'electrician', 'cleaning', 'repair', 'maintenance', 'ac repair', 'service booking',
            ]],
            ['key' => 'custom', 'label' => 'Custom (any business)', 'keywords' => []],
        ];
    }

    /** Source keys a campaign can listen to. */
    public static function sourceOptions(): array
    {
        return [
            ['key' => 'meta_lead_ads', 'label' => 'Meta Lead Ads (Facebook/Instagram)'],
            ['key' => 'website_form', 'label' => 'Website forms'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp'],
            ['key' => 'webhook', 'label' => 'Webhook / Google Ads'],
            ['key' => 'csv_import', 'label' => 'CSV/XLSX import'],
            ['key' => 'manual', 'label' => 'Manual entry'],
        ];
    }
}
