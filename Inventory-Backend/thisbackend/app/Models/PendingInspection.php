<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingInspection extends Model
{
    protected $table = 'pending_inspections';

    protected $primaryKey = 'idinspection';

    protected $fillable = [
        'idrequestItem',
        'iditems',
        'quantity',
        'status',
        'returned_at',
        'inspected_by',
        'inspected_at',
    ];

    public $timestamps = false;

    public function requestItem()
    {
        return $this->belongsTo(RequestItem::class, 'idrequestItem', 'idrequestItem');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'iditems', 'iditems');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by', 'idUsers');
    }
}
