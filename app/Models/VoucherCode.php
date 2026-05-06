<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherCode extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        // The full redeemable voucher code is sensitive. Laravel stores it
        // encrypted at rest and decrypts it only when application code reads it.
        'code' => 'encrypted',
        'amount' => 'decimal:2',
    ];
}
