<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'items';

    protected $primaryKey = 'iditems';

    protected $fillable = [
        'item_name',
        'description',
        'price',
        'category',
        'item_type',
        'quantity',
        'damaged_quantity',
        'status',
    ];

    public $timestamps = false;

    public function requestItems()
{
    return $this->hasMany(RequestItem::class, 'iditems', 'iditems');
}
}
