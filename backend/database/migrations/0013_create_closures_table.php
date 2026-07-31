<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('closures', function (Blueprint $t) {
            $t->id();
            $t->string('title', 191);
            $t->text('description')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('blocks_pickup')->default(true);
            $t->boolean('blocks_return')->default(true);
            $t->boolean('is_recurring_yearly')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->index(['start_date', 'end_date'], 'idx_closures_range');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('closures');
    }
};
