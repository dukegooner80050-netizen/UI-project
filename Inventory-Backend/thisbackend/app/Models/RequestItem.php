<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestItem extends Model
{
protected $table = 'requestitem';

protected $primaryKey = 'idrequestItem';

protected $fillable = [
    'idrequest',
    'iditems',
    'quantity',
    'returned_quantity',
];

public $timestamps = false;
public function request()
{
    return $this->belongsTo(Request::class, 'idrequest', 'idrequest');
}

public function item()
{
    return $this->belongsTo(Item::class, 'iditems', 'iditems');
}
}
