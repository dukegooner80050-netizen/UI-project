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
];

public $timestamps = false;

public function variants()
{
    return $this->hasMany(UniformVariant::class, 'idUniftype', 'idUniftype');
}
}

