<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    //
    protected $fillable = [
        'slug',
        'name',
        'is_active',
    ];

    public function offerImports()
    {
        return $this->hasMany(OfferImport::class);
    }

    public function offers()
    {
        return $this->hasMany(Offer::class);
    }
}
