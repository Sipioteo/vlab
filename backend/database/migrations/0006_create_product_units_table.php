<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('product_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('label', 32);
            $t->string('serial_number', 120)->nullable();
            $t->string('asset_code', 120)->nullable();
            $t->date('purchase_date')->nullable();
            $t->date('inspection_date')->nullable();
            $t->date('next_inspection_date')->nullable();
            $t->string('status', 32)->default('available');
            $t->string('condition_note', 255)->nullable();
            $t->string('location', 120)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique(['product_id', 'label'], 'uniq_product_units_product_label');
            $t->index('status', 'idx_product_units_status');
            $t->index('serial_number', 'idx_product_units_serial');
            $t->index('asset_code', 'idx_product_units_asset');
            $t->foreign('product_id', 'fk_product_units_product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('product_units');
    }
};
