<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stores extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Define one-to-many relationship with store_locations
     * A store can have multiple locations assigned to it
     * Locations are stored as strings in the store_locations table
     */
    public function storeLocations()
    {
        return $this->hasMany(StoreLocations::class, 'store_id', 'id');
    }
}
