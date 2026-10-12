<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestockRequest extends Model
{
    protected $table = 'restock_requests';

    protected $primaryKey = 'idrestock';

    public $timestamps = false;

    protected $fillable = [
        'iditems',
        'requested_by',
        'quantity_needed',
        'quantity_received',
        'status',
        'notes',
        'ordered_at',
        'closed_at',
        'closed_by',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class, 'iditems', 'iditems');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by', 'idUsers');
    }
}
