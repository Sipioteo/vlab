<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('regulation_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('regulation_id');
            $t->string('target_type', 16);
            $t->unsignedBigInteger('target_id');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['regulation_id', 'target_type', 'target_id'], 'uniq_regulation_targets');
            $t->index(['target_type', 'target_id'], 'idx_regulation_targets_lookup');
            $t->foreign('regulation_id', 'fk_regulation_targets_reg_id')->references('id')->on('regulations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('regulation_targets');
    }
};
