<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisposalRecord extends Model
{
    protected $table = 'disposal_records';

    protected $primaryKey = 'iddisposal';

    public $timestamps = false;

    protected $fillable = [
        'iditems',
        'item_name',
        'category',
        'item_type',
        'quantity',
        'method',
        'notes',
        'disposed_on',
        'disposed_by',
        'disposed_by_name',
        'created_at',
    ];

    protected $casts = [
        'disposed_on' => 'date',
        'created_at' => 'datetime',
    ];
}
