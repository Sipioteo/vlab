<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('refresh_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('token_hash', 64);
            $t->string('family_id', 36);
            $t->dateTime('expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('ip', 45)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique('token_hash', 'uniq_refresh_tokens_hash');
            $t->index('user_id', 'idx_refresh_tokens_user');
            $t->index('family_id', 'idx_refresh_tokens_family');
            $t->foreign('user_id', 'fk_refresh_tokens_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('refresh_tokens');
    }
};
