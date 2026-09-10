<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'chat_session_id', 'user_id', 'role', 'text',
        'tool_name', 'via', 'is_error', 'meta',
    ];

    protected function casts(): array
    {
        return ['is_error' => 'boolean', 'meta' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
