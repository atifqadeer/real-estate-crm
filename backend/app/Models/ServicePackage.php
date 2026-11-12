<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServicePackage extends Model
{
    protected $fillable = ['organization_id', 'name', 'price', 'currency', 'limits', 'is_active'];
    protected function casts(): array { return ['limits' => 'array', 'is_active' => 'boolean', 'price' => 'decimal:2']; }
}
