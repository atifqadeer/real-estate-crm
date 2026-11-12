<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AiContentGeneration extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
    protected $fillable = ['organization_id', 'user_id', 'platform', 'language', 'model', 'inputs', 'suggestions', 'created_at'];
    protected function casts(): array { return ['inputs' => 'array', 'suggestions' => 'array', 'created_at' => 'datetime']; }
}
