<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PiStatus extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'heartbeat_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'payload' => 'array',
    ];
}
