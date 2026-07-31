<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 120);
            $t->string('name', 191);
            $t->text('description')->nullable();
            $t->string('icon', 64)->nullable();
            $t->string('image_url', 1024)->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->integer('position')->default(0);
            $t->boolean('is_active')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique('slug', 'uniq_categories_slug');
            $t->index('parent_id', 'idx_categories_parent');
            $t->index('position', 'idx_categories_position');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('categories');
    }
};
