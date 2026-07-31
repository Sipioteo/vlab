<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Time-window model (SPEC v1.4 §5.3/§7.4): `pickup_time`/`return_time` were
 * already nullable — NULL now MEANS "the lab's configured window for that
 * weekday" instead of "missing data". A precise override is `*_time` alone;
 * a custom range override is `*_time` + the new `*_time_end`.
 */
return new class {
    public function up(): void
    {
        Capsule::schema()->table('orders', function (Blueprint $t) {
            $t->string('pickup_time_end', 5)->nullable()->after('pickup_time');
            $t->string('return_time_end', 5)->nullable()->after('return_time');
        });
    }

    public function down(): void
    {
        if (!Capsule::schema()->hasTable('orders') || !Capsule::schema()->hasColumn('orders', 'pickup_time_end')) {
            return;
        }
        Capsule::schema()->table('orders', function (Blueprint $t) {
            $t->dropColumn(['pickup_time_end', 'return_time_end']);
        });
    }
};
