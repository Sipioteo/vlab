<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('fake_ldap_users', function (Blueprint $t) {
            $t->id();
            $t->string('username', 191);
            $t->string('password_hash', 255);
            $t->string('email', 191)->nullable();
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('display_name', 191)->nullable();
            $t->text('groups')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique('username', 'uniq_fake_ldap_users_username');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('fake_ldap_users');
    }
};
