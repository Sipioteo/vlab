<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('ldap_uid', 191);
            $t->string('email', 191)->nullable();
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('display_name', 191)->nullable();
            $t->string('role', 32)->default('student');
            $t->boolean('role_locked')->default(false);
            $t->string('role_source', 32)->default('ldap');
            $t->string('matricola', 32)->nullable();
            $t->string('course', 191)->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('token_version')->default(1);
            $t->text('ldap_groups')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique('ldap_uid', 'uniq_users_ldap_uid');
            $t->index('role', 'idx_users_role');
            $t->index('email', 'idx_users_email');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('users');
    }
};
