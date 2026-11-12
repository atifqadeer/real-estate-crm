<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingAttempt extends Model
{
    public $timestamps = false; // uses started_at / finished_at instead

    protected $fillable = [
        'organization_id', 'content_post_target_id', 'attempt_no', 'status',
        'provider_request', 'provider_response', 'error_message', 'permanent', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['provider_request' => 'array', 'provider_response' => 'array', 'permanent' => 'boolean',
            'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function target(): BelongsTo { return $this->belongsTo(ContentPostTarget::class, 'content_post_target_id'); }
}
