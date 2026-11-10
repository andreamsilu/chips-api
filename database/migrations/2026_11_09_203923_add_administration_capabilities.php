<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'starts_at', 'expires_at']);
        });
        Schema::table('coupons', function (Blueprint $table): void {
            $table->foreignUlid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
        });
        Schema::table('products', fn (Blueprint $table) => $table->string('image_path', 255)->nullable());
        Schema::table('riders', function (Blueprint $table): void {
            $table->boolean('available')->default(true);
            $table->index(['status', 'available', 'created_at'], 'riders_admin_status_idx');
        });
        Schema::create('vendor_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->string('kind', 40);
            $table->string('storage_path', 255);
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->string('status', 20)->default('pending');
            $table->string('review_note', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'status', 'created_at']);
        });
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::create('admin_access_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users');
            $table->boolean('unrestricted')->default(false);
            $table->timestamps();
        });
        Schema::create('admin_permission_grants', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users');
            $table->string('permission', 80);
            $table->primary(['user_id', 'permission']);
        });
        foreach (['orders', 'payments', 'vendors', 'users', 'settlements', 'ledger_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index(['created_at', 'id'], $name.'_admin_created_idx'));
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'created_at', 'id'], 'orders_admin_status_idx');
            $table->index(['payment_status', 'created_at', 'id'], 'orders_admin_payment_idx');
        });
        Schema::table('deliveries', fn (Blueprint $table) => $table->index(['status', 'created_at', 'id'], 'deliveries_admin_status_idx'));
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->index(['account', 'created_at', 'id'], 'ledger_admin_account_idx'));
    }

    public function down(): void
    {
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropIndex('ledger_admin_account_idx'));
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropIndex('deliveries_admin_status_idx'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_admin_status_idx');
            $table->dropIndex('orders_admin_payment_idx');
        });
        foreach (['orders', 'payments', 'vendors', 'users', 'settlements', 'ledger_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex($name.'_admin_created_idx'));
        }
        Schema::dropIfExists('admin_permission_grants');
        Schema::dropIfExists('admin_access_profiles');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('vendor_documents');
        Schema::table('riders', function (Blueprint $table): void {
            $table->dropIndex('riders_admin_status_idx');
            $table->dropColumn('available');
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('image_path'));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropConstrainedForeignId('campaign_id'));
        Schema::dropIfExists('campaigns');
    }
};
