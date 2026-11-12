<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_templates';

    protected $fillable = ['organization_id', 'whatsapp_account_id', 'name', 'language', 'category', 'status', 'components', 'synced_at'];
    protected function casts(): array { return ['components' => 'array', 'synced_at' => 'datetime']; }

    /** The BODY component's text, for previews. */
    public function bodyText(): ?string
    {
        foreach ($this->components ?? [] as $component) {
            if (($component['type'] ?? null) === 'BODY') return $component['text'] ?? null;
        }
        return null;
    }

    /** Count of {{n}} placeholders in the body text. */
    public function bodyParameterCount(): int
    {
        $text = $this->bodyText() ?? '';
        preg_match_all('/\{\{\d+\}\}/', $text, $m);
        return count($m[0]);
    }
}
