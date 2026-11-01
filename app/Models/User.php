<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable, SoftDeletes;

    // v3.1.0 (L4): password, role and is_active removed — they must
    // never be mass-assignable from request data. Call sites now use
    // forceFill() (UserController store/update/toggleActive,
    // ProfileController password change, DatabaseSeeder).
    protected $fillable = [
        'name', 'username', 'email', 'phone', 'city', 'address',
        'id_card_number', 'image',
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
