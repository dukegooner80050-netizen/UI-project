<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomEquipment extends Model
{
    protected $table = 'room_equipment';

    protected $primaryKey = 'idroomequipment';

    protected $fillable = [
        'idroom',
        'iditems',
        'quantity',
    ];

    public $timestamps = false;

    public function room()
    {
        return $this->belongsTo(Room::class, 'idroom', 'idroom');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'iditems', 'iditems');
    }
}
