<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = ['organization_id', 'whatsapp_conversation_id', 'direction', 'wa_message_id',
        'type', 'body', 'media_url', 'template_name', 'status', 'error', 'sent_by', 'raw'];

    protected function casts(): array { return ['raw' => 'array']; }

    public function conversation(): BelongsTo { return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id'); }
}
