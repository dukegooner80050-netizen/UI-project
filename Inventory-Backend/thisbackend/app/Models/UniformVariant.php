<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniformVariant extends Model
{
    protected $table = 'uniformvariant';

protected $primaryKey = 'idUnifvariant';

protected $fillable = [
    'idUniftype',
    'iddept',
    'size',
    'price',
    'quantity',
];

public $timestamps = false;

public function department()
{
    return $this->belongsTo(Department::class, 'iddept', 'iddept');
}

public function type()
{
    return $this->belongsTo(UniformType::class, 'idUniftype', 'idUniftype');
}
}
