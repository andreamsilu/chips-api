<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_guest')->default(false)->index();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
        Schema::create('guest_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('guest_order_access_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->unique()->constrained('orders');
            $table->foreignUlid('guest_session_id')->constrained('guest_sessions');
            $table->char('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->orWhereNull('password')->exists()) {
            throw new RuntimeException('Guest owners exist. Deploy a forward fix instead of removing their access or ownership records.');
        }
        Schema::dropIfExists('guest_order_access_tokens');
        Schema::dropIfExists('guest_sessions');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_guest');
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
