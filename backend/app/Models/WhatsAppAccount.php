<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_accounts';

    protected $fillable = ['organization_id', 'waba_id', 'phone_number_id', 'display_phone_number',
        'verified_name', 'quality_rating', 'messaging_limit', 'access_token', 'status', 'connected_by'];

    protected $hidden = ['access_token'];

    protected function casts(): array { return ['access_token' => 'encrypted']; }

    public function organization(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Organization::class); }
    public function templates(): HasMany { return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id'); }
    public function conversations(): HasMany { return $this->hasMany(WhatsAppConversation::class, 'whatsapp_account_id'); }
}
