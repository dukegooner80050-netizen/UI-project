<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'idsetting';

    protected $fillable = [
        'setting_key',
        'setting_value',
    ];

    public $timestamps = false;
}
