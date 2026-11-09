<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sms_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('outbox_message_id')->unique()->constrained('outbox_messages');
            $table->text('recipient');
            $table->text('body');
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id', 100)->nullable()->unique();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_deliveries');
    }
};
