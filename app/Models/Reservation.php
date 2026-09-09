<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'client_reference',
        'customer_name',
        'customer_email',
        'check_in',
        'check_out',
        'price',
        'currency',
    ];

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'price' => 'decimal:2',
    ];
}
