<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_conversations';

    protected $fillable = ['organization_id', 'whatsapp_account_id', 'lead_id', 'contact_phone_e164',
        'contact_name', 'status', 'unread_count', 'window_expires_at', 'last_message_at', 'opted_out_at'];

    protected function casts(): array
    {
        return ['window_expires_at' => 'datetime', 'last_message_at' => 'datetime', 'opted_out_at' => 'datetime'];
    }

    public function account(): BelongsTo { return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id'); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function messages(): HasMany { return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id'); }

    public function windowOpen(): bool
    {
        return $this->window_expires_at && $this->window_expires_at->isFuture();
    }
}
