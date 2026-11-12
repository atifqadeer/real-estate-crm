<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentPost extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'organization_id', 'author_id', 'title', 'caption', 'hashtags', 'link_url', 'cta_text',
        'first_comment', 'internal_notes', 'status', 'approval_required', 'scheduled_at', 'published_at',
    ];

    protected function casts(): array
    {
        return ['approval_required' => 'boolean', 'scheduled_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function targets(): HasMany { return $this->hasMany(ContentPostTarget::class); }
    public function approvals(): HasMany { return $this->hasMany(ContentPostApproval::class); }
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'content_post_media')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /** Full text for a destination: override caption or base, plus hashtags. */
    public function effectiveText(ContentPostTarget $target): string
    {
        $caption = $target->caption_override ?? $this->caption ?? '';
        $hashtags = $target->hashtags_override ?? $this->hashtags ?? '';
        return trim(trim($caption) . ($hashtags ? "\n\n" . trim($hashtags) : ''));
    }
}
