<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'location',
        'avatar',
        'address',
        'state',
        'country',
        'latitude',
        'longitude',
        'has_location',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'has_location'      => 'boolean',
        'latitude'          => 'float',
        'longitude'         => 'float',
        'phone'             => 'string',
        'location'          => 'string',
        'avatar'            => 'string',
    ];

    public function isNewUser(): bool
    {
        return $this->created_at?->gt(now()->subHours(24)) ?? false;
    }
}