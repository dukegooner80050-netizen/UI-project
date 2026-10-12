<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $table = 'stock_adjustments';

    protected $primaryKey = 'idstockadj';

    public $timestamps = false;

    protected $fillable = [
        'iditems',
        'idUsers',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'reason',
        'notes',
        'status',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'iditems', 'iditems');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'idUsers', 'idUsers');
    }
}
