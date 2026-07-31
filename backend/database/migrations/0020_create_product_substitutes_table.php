<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('product_substitutes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('substitute_product_id');
            $t->integer('priority')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['product_id', 'substitute_product_id'], 'uniq_substitute_pair');
            $t->index('product_id', 'idx_substitute_product');
            $t->foreign('product_id', 'fk_product_substitutes_product_id')->references('id')->on('products')->onDelete('cascade');
            $t->foreign('substitute_product_id', 'fk_product_substitutes_sub_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('product_substitutes');
    }
};
