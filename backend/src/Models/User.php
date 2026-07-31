<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 */
class User extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'role_locked' => 'bool',
        'is_active' => 'bool',
        'token_version' => 'int',
    ];

    public function isStaff(): bool
    {
        return in_array($this->role, ['technician', 'assistant', 'admin'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function displayName(): string
    {
        if ($this->display_name !== null && $this->display_name !== '') {
            return $this->display_name;
        }
        $full = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
        return $full !== '' ? $full : (string) $this->ldap_uid;
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id');
    }
}
