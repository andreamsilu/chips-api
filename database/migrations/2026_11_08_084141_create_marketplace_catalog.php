<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100)->unique();
            $table->timestamps();

        });
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_location_id')->constrained('vendor_locations');
            $table->foreignUlid('category_id')->constrained('categories');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('available')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vendor_location_id', 'available', 'category_id']);
        });
        Schema::create('carts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained();
            $table->foreignUlid('vendor_location_id')->nullable()->constrained('vendor_locations');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

        });
        Schema::create('cart_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('cart_id')->constrained('carts');
            $table->foreignUlid('product_id')->constrained('products');
            $table->unsignedSmallInteger('quantity');
            $table->string('instructions', 500)->nullable();
            $table->timestamps();
            $table->unique(['cart_id', 'product_id']);
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique();
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->unsignedBigInteger('discount');
            $table->unsignedBigInteger('minimum_subtotal')->default(0);
            $table->unsignedInteger('usage_limit');
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->boolean('active')->default(true);
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');

    }
};
