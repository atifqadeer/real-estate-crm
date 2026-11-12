<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LeadImport extends Model
{
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'user_id', 'filename', 'total_rows', 'created_count',
        'touchpoint_count', 'possible_duplicate_count', 'invalid_count', 'row_errors'];
    protected function casts(): array { return ['row_errors' => 'array']; }
}
