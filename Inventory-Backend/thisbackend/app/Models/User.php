<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Table name
     */
    protected $table = 'users';

    /**
     * Primary Key
     */
    protected $primaryKey = 'idUsers';

    /**
     * Auto Increment
     */
    public $incrementing = true;

    /**
     * Primary key type
     */
    protected $keyType = 'int';

    /**
     * Mass assignable
     */
    protected $fillable = [
        'full_name',
        'username',
        'password',
        'role',
    ];

    /**
     * Hidden fields
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Casts
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
    
    public function requests()
{
    return $this->hasMany(Request::class, 'idUsers', 'idUsers');
}

public function transactions()
{
    return $this->hasMany(Transaction::class, 'idUsers', 'idUsers');
}

public function logs()
{
    return $this->hasMany(Log::class, 'idUsers', 'idUsers');
}
}