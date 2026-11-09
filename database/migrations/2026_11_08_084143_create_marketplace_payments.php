<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->unique()->constrained();
            $table->string('merchant_reference', 80)->unique();
            $table->string('provider_reference', 160)->nullable()->unique();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('TZS');
            $table->text('phone');
            $table->text('request_payload');
            $table->timestamp('first_attempt_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->constrained('payments');
            $table->string('provider_event_id', 160)->unique();
            $table->string('type', 40);
            $table->char('payload_hash', 64);
            $table->timestamps();

        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->unique()->constrained();
            $table->foreignUlid('payment_id')->nullable()->constrained('payments');
            $table->unsignedBigInteger('amount');
            $table->string('status', 30)->default('requested');
            $table->string('reason', 500);
            $table->text('request_payload')->nullable();
            $table->string('external_receipt', 160)->nullable()->unique();
            $table->timestamp('first_attempt_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('provider_reference', 160)->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('riders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('status', 20)->default('approved');
            $table->string('vehicle', 100);
            $table->timestamps();

        });
        Schema::create('deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->unique()->constrained();
            $table->foreignUlid('rider_id')->nullable()->constrained('riders');
            $table->string('status', 30)->default('unassigned');
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['rider_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('riders');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');

    }
};
