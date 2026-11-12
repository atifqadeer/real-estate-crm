<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationPreference extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'disabled_types'];
    protected function casts(): array { return ['disabled_types' => 'array']; }
}
