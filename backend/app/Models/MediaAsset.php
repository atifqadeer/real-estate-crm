<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'organization_id', 'uploaded_by', 'disk', 'path', 'public_url', 'original_name',
        'mime_type', 'extension', 'size_bytes', 'width', 'height', 'duration_seconds', 'kind',
    ];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
