<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRecord extends Model
{
    protected $table = 'maintenance_records';

    protected $primaryKey = 'idmaintenance';

    public $timestamps = false;

    protected $fillable = [
        'iditems',
        'item_name',
        'category',
        'idinspection',
        'source',
        'origin',
        'idroom',
        'building_name',
        'room_name',
        'problem',
        'quantity',
        'repaired_qty',
        'unrepairable_qty',
        'status',
        'sent_at',
        'sent_by_name',
        'closed_at',
        'closed_by_name',
        'resolution_notes',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function remaining(): int
    {
        return max((int) $this->quantity - (int) $this->repaired_qty - (int) $this->unrepairable_qty, 0);
    }
}
