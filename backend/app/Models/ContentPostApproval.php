<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPostApproval extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'content_post_id', 'actor_id', 'action', 'comment'];
    protected function casts(): array { return ['created_at' => 'datetime']; }

    public function post(): BelongsTo { return $this->belongsTo(ContentPost::class, 'content_post_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
