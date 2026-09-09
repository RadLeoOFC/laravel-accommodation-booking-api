<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'property_id',
        'offer_import_id',
        'external_id',
        'check_in',
        'check_out',
        'max_guests',
        'price',
        'currency',
        'available_units',
        'expires_at',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function offerImport()
    {
        return $this->belongsTo(OfferImport::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'expires_at' => 'datetime',
        'price' => 'decimal:2',
    ];
}
