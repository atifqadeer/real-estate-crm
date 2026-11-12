<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadSavedFilter extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'user_id', 'name', 'criteria', 'is_shared'];
    protected function casts(): array { return ['criteria' => 'array', 'is_shared' => 'boolean']; }
}
