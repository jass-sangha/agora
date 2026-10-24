<?php

namespace Chat\Models;

use Chat\Chat;
use Chat\Traits\BelongsToChatable;
use Illuminate\Database\Eloquent\Model;

class UserPresence extends Model
{
    use BelongsToChatable;

    protected $fillable = ['chatable_id', 'chatable_type', 'last_seen_at', 'is_online'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_online' => 'boolean',
    ];

    public function getTable(): string
    {
        return Chat::table('user_presence');
    }
}
