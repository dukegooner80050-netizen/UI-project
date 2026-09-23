<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'dept';

    protected $primaryKey = 'iddept';

    protected $fillable = [
        'dept_name',
    ];

    public $timestamps = false;

    public function uniformVariants()
{
    return $this->hasMany(UniformVariant::class, 'iddept', 'iddept');
}
}