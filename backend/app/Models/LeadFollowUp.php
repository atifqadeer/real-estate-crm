<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFollowUp extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'lead_id', 'user_id', 'due_at', 'note', 'status', 'completed_at'];
    protected function casts(): array { return ['due_at' => 'datetime', 'completed_at' => 'datetime']; }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_at->isPast();
    }
}
