<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
protected $table = 'request';

protected $primaryKey = 'idrequest';

protected $fillable = [
    'idUsers',
    'approved_by',
    'request_date',
    'status',
    'location',
    'room',
    'purpose',
    'rejectReason',
    'borrowed_at',
];

public $timestamps = false;

public function user()
{
    return $this->belongsTo(User::class, 'idUsers', 'idUsers');
}

public function items()
{
    return $this->hasMany(RequestItem::class, 'idrequest', 'idrequest');
}

public function uniforms()
{
    return $this->hasMany(RequestUniform::class, 'idrequest', 'idrequest');
}
}
