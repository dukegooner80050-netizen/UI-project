<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $table = 'rooms';

    protected $primaryKey = 'idroom';

    protected $fillable = [
        'idbuilding',
        'room_name',
    ];

    public $timestamps = false;

    public function building()
    {
        return $this->belongsTo(Building::class, 'idbuilding', 'idbuilding');
    }

    public function equipment()
    {
        return $this->hasMany(RoomEquipment::class, 'idroom', 'idroom');
    }
}
