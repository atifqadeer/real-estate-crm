<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccountPermission extends Model
{
    protected $fillable = ['social_account_id', 'permission', 'status'];
    public function account(): BelongsTo { return $this->belongsTo(SocialAccount::class, 'social_account_id'); }
}
