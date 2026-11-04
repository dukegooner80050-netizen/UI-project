<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniformType extends Model
{
    protected $table = 'uniformtype';

protected $primaryKey = 'idUniftype';

protected $fillable = [
    'uniform_name',
    'description',
    'iddept',
];

public $timestamps = false;

public function variants()
{
    return $this->hasMany(UniformVariant::class, 'idUniftype', 'idUniftype');
}

/* The course that owns this type; null means every course can use it. */
public function department()
{
    return $this->belongsTo(Department::class, 'iddept', 'iddept');
}
}

