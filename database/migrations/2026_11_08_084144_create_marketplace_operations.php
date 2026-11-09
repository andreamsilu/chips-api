<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders');
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->string('account', 40);
            $table->string('kind', 40);
            $table->bigInteger('amount');
            $table->char('currency', 3)->default('TZS');
            $table->timestamps();
            $table->unique(['order_id', 'account', 'kind']);
            $table->index(['vendor_id', 'account', 'created_at']);
        });
        Schema::create('settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->bigInteger('amount');
            $table->string('status', 30)->default('prepared');
            $table->string('external_reference', 160)->nullable()->unique();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

        });
        Schema::create('settlement_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('settlement_id')->constrained('settlements');
            $table->foreignUlid('ledger_entry_id')->constrained();
            $table->unique(['settlement_id', 'ledger_entry_id']);
            $table->index('ledger_entry_id');
            $table->timestamps();

        });
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->string('operation', 60);
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->ulid('resource_id');
            $table->timestamps();
            $table->unique(['user_id', 'operation', 'key'], 'idempotency_scope_unique');
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action', 80);
            $table->string('subject_type', 80);
            $table->string('subject_id', 80);
            $table->json('metadata');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders');
            $table->json('payload');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'created_at']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignUlid('vendor_id')->constrained('vendors');
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 2000)->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'created_at']);
        });
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignUlid('order_id')->nullable()->constrained('orders');
            $table->string('subject', 160);
            $table->string('status', 30)->default('open');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('support_ticket_id')->constrained('support_tickets');
            $table->foreignId('user_id')->constrained('users');
            $table->text('body');
            $table->timestamps();

        });
        if (DB::getDriverName() === 'mysql') {
            foreach (['vendors' => 'commission_bps <= 10000', 'vendor_locations' => 'latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180', 'addresses' => 'latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180', 'orders' => 'total = subtotal - discount + delivery_fee AND discount <= subtotal AND commission <= subtotal - discount', 'cart_items' => 'quantity BETWEEN 1 AND 20', 'reviews' => 'rating BETWEEN 1 AND 5'] as $table => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_valid CHECK ({$check})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('settlement_entries');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('ledger_entries');

    }
};
