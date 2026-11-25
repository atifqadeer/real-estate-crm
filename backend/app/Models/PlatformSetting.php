<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of the global platform_settings table (see PlatformSettings service). */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
