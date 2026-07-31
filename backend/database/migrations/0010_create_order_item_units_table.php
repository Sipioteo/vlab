<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('order_item_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedBigInteger('product_unit_id');
            $t->dateTime('assigned_at');
            $t->dateTime('returned_at')->nullable();
            $t->string('condition_out', 32)->nullable();
            $t->string('condition_in', 32)->nullable();
            $t->string('note', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['order_item_id', 'product_unit_id'], 'uniq_order_item_units');
            $t->index('product_unit_id', 'idx_order_item_units_unit');
            $t->foreign('order_item_id', 'fk_order_item_units_item_id')->references('id')->on('order_items')->onDelete('cascade');
            $t->foreign('product_unit_id', 'fk_order_item_units_unit_id')->references('id')->on('product_units')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('order_item_units');
    }
};
