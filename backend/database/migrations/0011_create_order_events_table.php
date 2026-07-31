<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('order_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->string('action', 64);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_type', 16)->default('user');
            $t->string('actor_role', 32)->nullable();
            $t->text('comment')->nullable();
            $t->text('meta')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['order_id', 'created_at'], 'idx_order_events_order');
            $t->foreign('order_id', 'fk_order_events_order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('order_events');
    }
};
