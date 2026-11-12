<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPostTarget extends Model
{
    protected $fillable = [
        'content_post_id', 'social_account_id', 'platform', 'caption_override', 'hashtags_override',
        'status', 'platform_post_id', 'platform_url', 'error_code', 'error_message',
        'attempts', 'next_retry_at', 'dispatched_at', 'published_at', 'provider_response', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['provider_response' => 'array', 'next_retry_at' => 'datetime', 'dispatched_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function post(): BelongsTo { return $this->belongsTo(ContentPost::class, 'content_post_id'); }
    public function account(): BelongsTo { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
    public function attemptsLog(): HasMany { return $this->hasMany(PublishingAttempt::class); }

    public function progress(string $key, mixed $default = null): mixed
    {
        return $this->provider_response['progress'][$key] ?? $default;
    }

    public function setProgress(string $key, mixed $value): void
    {
        $response = $this->provider_response ?? [];
        $response['progress'][$key] = $value;
        $this->provider_response = $response;
        $this->save();
    }
}
