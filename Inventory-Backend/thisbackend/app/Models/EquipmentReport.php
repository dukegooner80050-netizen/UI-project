<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentReport extends Model
{
    protected $table = 'equipment_reports';

    protected $primaryKey = 'idreport';

    public $timestamps = false;

    protected $fillable = [
        'iditems',
        'idroom',
        'building_name',
        'room_name',
        'quantity',
        'report_type',
        'description',
        'status',
        'reported_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'idinspection',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'iditems', 'iditems');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by', 'idUsers');
    }
}
