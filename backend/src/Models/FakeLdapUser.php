<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FakeLdapUser extends Model
{
    protected $table = 'fake_ldap_users';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'bool',
    ];
}
