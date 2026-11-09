<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('suspended_at')->nullable();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
        });
        foreach (['customer', 'vendor', 'rider', 'support', 'admin'] as $role) {
            DB::table('roles')->insert(['name' => $role]);
        }
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('role_id')->constrained();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->string('label', 80);
            $table->string('details', 500);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'deleted_at']);
        });
        Schema::create('vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('commission_bps')->default(1000);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
        });
        Schema::create('vendor_members', function (Blueprint $table) {
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->foreignId('user_id')->constrained('users');
            $table->primary(['vendor_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('vendor_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->string('name', 160);
            $table->string('address', 500);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('accepting_orders')->default(false);
            $table->boolean('delivery_enabled')->default(true);
            $table->boolean('pickup_enabled')->default(true);
            $table->unsignedSmallInteger('delivery_radius_km')->default(15);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['accepting_orders', 'latitude', 'longitude']);
            $table->index(['vendor_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_locations');
        Schema::dropIfExists('vendor_members');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['public_id', 'phone', 'suspended_at']));
    }
};
