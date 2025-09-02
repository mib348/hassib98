<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreLocations extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Define the inverse relationship with stores
     * Each store_location entry belongs to a store
     */
    public function store()
    {
        return $this->belongsTo(Stores::class, 'store_id', 'id');
    }
}
