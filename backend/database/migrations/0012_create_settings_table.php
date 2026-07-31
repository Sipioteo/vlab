<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 120);
            $t->text('value')->nullable();
            $t->string('type', 24);
            $t->string('group', 48);
            $t->string('label_it', 191);
            $t->string('description_it', 500)->nullable();
            $t->boolean('is_public')->default(false);
            $t->boolean('is_secret')->default(false);
            $t->boolean('nullable')->default(false);
            $t->text('options')->nullable();
            $t->integer('position')->default(0);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique('key', 'uniq_settings_key');
            $t->index(['group', 'position'], 'idx_settings_group');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('settings');
    }
};
