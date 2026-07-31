<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-user obfuscated calendar feed token. The token IS the credential for
 * GET /api/v1/ical/{token}.ics, so it is long, random and rotatable.
 */
return new class {
    public function up(): void
    {
        Capsule::schema()->table('users', function (Blueprint $t) {
            $t->string('ical_token', 64)->nullable();
            $t->dateTime('ical_token_generated_at')->nullable();
            $t->unique('ical_token', 'uniq_users_ical_token');
        });
    }

    public function down(): void
    {
        if (!Capsule::schema()->hasTable('users') || !Capsule::schema()->hasColumn('users', 'ical_token')) {
            return;
        }
        Capsule::schema()->table('users', function (Blueprint $t) {
            $t->dropUnique('uniq_users_ical_token');
            $t->dropColumn(['ical_token', 'ical_token_generated_at']);
        });
    }
};
