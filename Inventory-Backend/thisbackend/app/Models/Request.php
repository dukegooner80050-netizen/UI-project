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
    'approval_remarks',
    'received_at',
    'borrowed_at',
    'coordinator_forwarded_by',
    'coordinator_forwarded_at',
    'notified_at',
    'notified_by',
    'release_method',
    'released_at',
    'released_by',
    'released_to_student',
    'released_student_id',
    'student_name',
    'student_number',
    'stock_held',
    'paid_at',
    'paid_by',
    'direct_to_amo_reason',
    'coord_review_status',
    'coord_reviewed_by',
    'coord_reviewed_at',
    'coord_review_note',
];

public $timestamps = false;

public function user()
{
    return $this->belongsTo(User::class, 'idUsers', 'idUsers');
}

public function forwardedByUser()
{
    return $this->belongsTo(User::class, 'coordinator_forwarded_by', 'idUsers');
}

public function releasedByUser()
{
    return $this->belongsTo(User::class, 'released_by', 'idUsers');
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
