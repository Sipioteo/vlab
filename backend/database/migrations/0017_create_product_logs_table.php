<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('product_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('product_unit_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('user_id');
            $t->string('type', 32);
            $t->string('severity', 32)->default('info');
            $t->string('title', 191);
            $t->text('body')->nullable();
            $t->dateTime('occurred_at');
            $t->dateTime('resolved_at')->nullable();
            $t->boolean('is_public')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->index(['product_id', 'occurred_at'], 'idx_product_logs_product');
            $t->index('product_unit_id', 'idx_product_logs_unit');
            $t->index('type', 'idx_product_logs_type');
            $t->index('order_id', 'idx_product_logs_order');
            $t->foreign('product_id', 'fk_product_logs_product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('product_logs');
    }
};
