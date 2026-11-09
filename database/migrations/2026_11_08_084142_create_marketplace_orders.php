<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->foreignUlid('vendor_location_id')->constrained('vendor_locations');
            $table->string('fulfilment', 20);
            $table->string('payment_method', 20);
            $table->string('payment_status', 30)->default('pending');
            $table->string('status', 30)->default('awaiting_payment');
            $table->char('currency', 3)->default('TZS');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('commission')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedSmallInteger('commission_bps');
            $table->json('address_snapshot');
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('stock_released_at')->nullable();
            $table->timestamp('cash_collected_at')->nullable();
            $table->foreignId('cash_collected_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['vendor_id', 'status', 'created_at']);
            $table->index(['status', 'payment_expires_at']);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders');
            $table->foreignUlid('product_id')->constrained('products');
            $table->string('name', 160);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('line_total');
            $table->string('instructions', 500)->nullable();
            $table->timestamps();

        });
        Schema::create('order_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at', 'id']);
        });
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('coupon_id')->constrained('coupons');
            $table->foreignUlid('order_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('order_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

    }
};
