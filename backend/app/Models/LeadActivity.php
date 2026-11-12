<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'lead_id', 'actor_id', 'type', 'description', 'metadata'];
    protected function casts(): array { return ['metadata' => 'array', 'created_at' => 'datetime']; }

    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
