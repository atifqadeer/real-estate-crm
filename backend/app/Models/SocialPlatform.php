<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPlatform extends Model
{
    protected $fillable = ['key', 'name', 'is_connectable', 'capabilities', 'notes'];
    protected function casts(): array { return ['is_connectable' => 'boolean', 'capabilities' => 'array']; }
    public function accounts(): HasMany { return $this->hasMany(SocialAccount::class); }
}
