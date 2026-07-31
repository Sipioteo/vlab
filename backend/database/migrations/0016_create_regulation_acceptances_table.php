<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('regulation_acceptances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('regulation_id');
            $t->unsignedBigInteger('user_id');
            $t->integer('version');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->dateTime('accepted_at');
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['regulation_id', 'user_id', 'version'], 'uniq_regulation_acceptances');
            $t->index('user_id', 'idx_regulation_acceptances_user');
            $t->foreign('regulation_id', 'fk_regulation_acceptances_reg_id')->references('id')->on('regulations')->onDelete('cascade');
            $t->foreign('user_id', 'fk_regulation_acceptances_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('regulation_acceptances');
    }
};
