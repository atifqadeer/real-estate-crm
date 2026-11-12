<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentRule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'match_type', 'match_value', 'assign_to_user_id', 'is_active', 'sort_order'];
    protected function casts(): array { return ['is_active' => 'boolean']; }

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assign_to_user_id'); }
}
