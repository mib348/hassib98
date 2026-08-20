<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fulfillment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $primaryKey = 'order_id';

    /**
     * These pickup fields arrive from the Pi (and the web form) as ARRAYS, e.g.
     * status = ["fulfilled","packed","handed_over"]. Their DB columns are plain
     * strings/text, so writing the raw array threw
     * `PDO::quote(): ... array given` and the whole fulfillment save aborted —
     * meaning a picked order was never recorded (no row, and the follow-up
     * Shopify metafield write never ran). Casting them to `array` makes Eloquent
     * JSON-encode on write and decode on read, so the save succeeds and the
     * pickup is finally stored. The encoded value (e.g. 36 chars for status)
     * fits the existing columns for the payloads the device actually sends.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'array',
        'items-bought' => 'array',
        'right-items-removed' => 'array',
        'wrong-items-removed' => 'array',
        'time-of-pick-up' => 'array',
        'door-open-time' => 'array',
        'image-before' => 'array',
        'image-after' => 'array',
    ];
}
