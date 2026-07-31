<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('category_id');
            $t->string('slug', 191);
            $t->string('name', 255);
            $t->string('brand', 120)->nullable();
            $t->string('model', 120)->nullable();
            $t->text('description')->nullable();
            $t->text('specs')->nullable();
            $t->string('image_url', 1024)->nullable();
            $t->string('status', 32)->default('available');
            $t->string('loan_mode', 32)->default('takeaway');
            $t->boolean('requires_training')->default(false);
            $t->integer('min_loan_days')->nullable();
            $t->integer('max_loan_days')->nullable();
            $t->string('replacement_value_note', 255)->nullable();
            $t->text('source_notes')->nullable();
            $t->integer('position')->default(0);
            $t->boolean('is_featured')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique('slug', 'uniq_products_slug');
            $t->index('category_id', 'idx_products_category');
            $t->index('status', 'idx_products_status');
            $t->index('name', 'idx_products_name');
            $t->index('brand', 'idx_products_brand');
            $t->index('is_featured', 'idx_products_featured');
            $t->foreign('category_id', 'fk_products_category_id')->references('id')->on('categories')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('products');
    }
};
