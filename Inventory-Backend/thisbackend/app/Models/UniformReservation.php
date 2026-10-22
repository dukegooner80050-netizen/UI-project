<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniformReservation extends Model
{
    protected $table = 'uniform_reservations';

    protected $primaryKey = 'idreservation';

    public $timestamps = false;

    protected $fillable = [
        'idUsers',
        'idUnifvariant',
        'quantity',
        'status',
        'notified_at',
        'cancelled_at',
        'submitted_at',
        'created_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'created_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'idUsers', 'idUsers');
    }

    public function variant()
    {
        return $this->belongsTo(UniformVariant::class, 'idUnifvariant', 'idUnifvariant')
            ->with(['type', 'department']);
    }
}
