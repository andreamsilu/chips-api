<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->foreignUlid('vendor_id')->nullable()->constrained('vendors');
            $table->index(['vendor_id', 'status', 'available'], 'riders_vendor_status_available_idx');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->dropForeign(['vendor_id']);
            $table->dropIndex('riders_vendor_status_available_idx');
            $table->dropColumn('vendor_id');
        });
    }
};
