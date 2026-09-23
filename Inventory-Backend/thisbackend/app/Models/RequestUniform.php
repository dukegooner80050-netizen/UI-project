<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestUniform extends Model
{
protected $table = 'requestuniform';

protected $primaryKey = 'idrequestUniform';

protected $fillable = [
    'idrequest',
    'idUnifvariant',
    'quantity',
];

public $timestamps = false;
public function request()
{
    return $this->belongsTo(Request::class, 'idrequest', 'idrequest');
}

public function uniformVariant()
{
    return $this->belongsTo(UniformVariant::class, 'idUnifvariant', 'idUnifvariant');
}
}
