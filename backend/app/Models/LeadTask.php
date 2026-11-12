<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTask extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'lead_id', 'title', 'description', 'assigned_to', 'created_by', 'due_at', 'status', 'priority', 'completed_at'];
    protected function casts(): array { return ['due_at' => 'datetime', 'completed_at' => 'datetime']; }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
