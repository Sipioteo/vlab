<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('recommended_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('recommended_product_id');
            $t->string('relation', 32)->default('accessory');
            $t->integer('position')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['product_id', 'recommended_product_id'], 'uniq_recommended_pair');
            $t->index('product_id', 'idx_recommended_product');
            $t->foreign('product_id', 'fk_recommended_products_product_id')->references('id')->on('products')->onDelete('cascade');
            $t->foreign('recommended_product_id', 'fk_recommended_products_rec_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('recommended_products');
    }
};
