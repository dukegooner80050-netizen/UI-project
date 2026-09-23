<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
protected $table = 'transactions';

protected $primaryKey = 'idtransactions';

protected $fillable = [
    'idUsers',
    'transaction_type',
    'transaction_date',
];

public $timestamps = false;

public function user()
{
    return $this->belongsTo(User::class, 'idUsers', 'idUsers');
}
}
