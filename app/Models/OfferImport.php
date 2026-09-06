<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\OfferImportStatus;

class OfferImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'external_import_id',
        'sent_at',
        'status',
        'payload',
        'total_offers',
        'processed_offers',
        'error',
        'started_at',
        'completed_at',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function offers()
    {
        return $this->hasMany(Offer::class);
    }

    protected $casts = [
        'sent_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'payload' => 'array',
        'status' => ImportStatus::class,
    ];
}
