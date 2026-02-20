<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| OrderDetailsForRpi Model
|--------------------------------------------------------------------------
|
| Represents a single order-detail record submitted by a Raspberry Pi
| at a store location. RPIs send pickup data (order info, product counts,
| camera images, timestamps) via the API, and this model stores it all.
|
| JSON columns (opening_count, closing_count, metafields) are auto-cast
| to PHP arrays so you can work with them directly without json_decode().
|
*/

class OrderDetailsForRpi extends Model
{
    use HasFactory;

    /**
     * The database table used by this model.
     * Matches the migration table name exactly.
     */
    protected $table = 'order_details_for_rpi';

    /**
     * Allow mass assignment on all columns.
     * Follows the same pattern as other models in this project (Locations, Orders, etc.)
     */
    protected $guarded = [];

    /**
     * Cast JSON columns to arrays and datetime strings to Carbon instances.
     * This way you can access $record->opening_count as an array directly,
     * and $record->pickup_date as a Carbon object for date formatting.
     */
    protected $casts = [
        'opening_count' => 'array',
        'closing_count' => 'array',
        'metafields'    => 'array',
        'current_date'  => 'datetime',
        'pickup_date'   => 'datetime',
    ];
}
