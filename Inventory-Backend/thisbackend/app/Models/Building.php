<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    protected $table = 'buildings';

    protected $primaryKey = 'idbuilding';

    protected $fillable = [
        'building_name',
    ];

    public $timestamps = false;

    public function rooms()
    {
        return $this->hasMany(Room::class, 'idbuilding', 'idbuilding');
    }
}
