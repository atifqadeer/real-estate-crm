<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadPipelineStage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'name', 'slug', 'sort_order', 'is_won', 'is_lost', 'is_system'];
    protected function casts(): array { return ['is_won' => 'boolean', 'is_lost' => 'boolean', 'is_system' => 'boolean']; }

    public function leads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Lead::class, 'lead_pipeline_stage_id');
    }

    /** Default pipeline from the product prompt (section 13). */
    public static function defaults(): array
    {
        return [
            ['name' => 'New', 'slug' => 'new'],
            ['name' => 'Contacted', 'slug' => 'contacted'],
            ['name' => 'Qualified', 'slug' => 'qualified'],
            ['name' => 'Interested', 'slug' => 'interested'],
            ['name' => 'Viewing Scheduled', 'slug' => 'viewing_scheduled'],
            ['name' => 'Negotiation', 'slug' => 'negotiation'],
            ['name' => 'Converted', 'slug' => 'converted', 'is_won' => true],
            ['name' => 'Lost', 'slug' => 'lost', 'is_lost' => true],
        ];
    }
}
