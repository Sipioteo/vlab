<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('regulations', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 120);
            $t->string('title', 191);
            $t->string('summary', 500)->nullable();
            $t->string('scope', 32)->default('global');
            $t->string('content_type', 16)->default('markdown');
            $t->text('body')->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('file_name', 255)->nullable();
            $t->integer('file_size')->nullable();
            $t->string('file_mime', 100)->nullable();
            $t->integer('version')->default(1);
            $t->boolean('requires_acceptance')->default(true);
            $t->boolean('is_active')->default(true);
            $t->dateTime('published_at')->nullable();
            $t->integer('position')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique('slug', 'uniq_regulations_slug');
            $t->index(['scope', 'is_active'], 'idx_regulations_scope');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('regulations');
    }
};
