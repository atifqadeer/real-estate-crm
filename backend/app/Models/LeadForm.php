<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadForm extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['organization_id', 'name', 'public_key', 'property_reference', 'intent', 'success_message', 'is_active', 'submissions_count'];
    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
