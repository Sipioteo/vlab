<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Capsule::schema()->create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->nullable();
            $t->integer('year_sequence')->nullable();
            $t->unsignedBigInteger('user_id');
            $t->string('status', 32)->default('draft');
            $t->date('pickup_date')->nullable();
            $t->string('pickup_time', 5)->nullable();
            $t->date('return_date')->nullable();
            $t->string('return_time', 5)->nullable();
            $t->dateTime('picked_up_at')->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->string('subject', 191)->nullable();
            $t->text('motivation')->nullable();
            $t->string('professor', 191)->nullable();
            $t->text('notes')->nullable();
            $t->text('staff_notes')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->boolean('exceeds_limits')->default(false);
            $t->text('limit_violations')->nullable();
            $t->integer('items_count')->default(0);
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->unsignedBigInteger('handed_over_by')->nullable();
            $t->unsignedBigInteger('received_by')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->integer('late_days')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique('code', 'uniq_orders_code');
            $t->index(['user_id', 'status'], 'idx_orders_user_status');
            $t->index('status', 'idx_orders_status');
            $t->index('pickup_date', 'idx_orders_pickup');
            $t->index('return_date', 'idx_orders_return');
            $t->index('submitted_at', 'idx_orders_submitted');
            $t->foreign('user_id', 'fk_orders_user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('orders');
    }
};
