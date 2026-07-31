<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(1);
            $t->string('product_name_snapshot', 255)->nullable();
            $t->string('product_brand_snapshot', 120)->nullable();
            $t->string('notes', 255)->nullable();
            $t->integer('returned_quantity')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['order_id', 'product_id'], 'uniq_order_items_order_product');
            $t->index('product_id', 'idx_order_items_product');
            $t->foreign('order_id', 'fk_order_items_order_id')->references('id')->on('orders')->onDelete('cascade');
            $t->foreign('product_id', 'fk_order_items_product_id')->references('id')->on('products')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('order_items');
    }
};
