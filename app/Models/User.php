<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'username', 'email', 'phone', 'city', 'address',
        'id_card_number', 'image', 'password', 'role', 'is_active',
    ];
    protected $hidden = ['password', 'remember_token'];

    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isManager(): bool { return $this->role === 'manager'; }
    public function isCashier(): bool { return $this->role === 'cashier'; }

    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/users/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=1e293b&color=fff&size=128';
    }
}
