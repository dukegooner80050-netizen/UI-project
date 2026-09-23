<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
protected $table = 'logs';

protected $primaryKey = 'idlogs';

protected $fillable = [
    'idUsers',
    'activity_description',
    'timestamp',
];

public $timestamps = false;

public function user()
{
    return $this->belongsTo(User::class, 'idUsers', 'idUsers');
}
}
