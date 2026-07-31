<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 64);
            $t->string('entity_type', 64)->nullable();
            $t->string('entity_id', 64)->nullable();
            $t->text('changes')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['entity_type', 'entity_id'], 'idx_audit_logs_entity');
            $t->index(['user_id', 'created_at'], 'idx_audit_logs_user');
            $t->index('action', 'idx_audit_logs_action');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('audit_logs');
    }
};
