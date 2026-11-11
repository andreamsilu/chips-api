<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('formatted_address', 500)->nullable();
            $table->string('google_place_id', 255)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('delivery_instructions', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('default_user_id')->nullable()->virtualAs('CASE WHEN is_default = 1 AND deleted_at IS NULL THEN user_id ELSE NULL END');
            $table->unique('default_user_id');
            $table->index(['user_id', 'is_default', 'deleted_at']);
        });
        Schema::table('vendor_locations', function (Blueprint $table): void {
            $table->string('google_place_id', 255)->nullable();
            $table->string('location_verification_status', 20)->default('pending');
            $table->timestamp('location_verified_at')->nullable();
            $table->foreignId('location_verified_by')->nullable()->constrained('users');
            $table->index(['location_verification_status', 'delivery_enabled', 'accepting_orders'], 'locations_delivery_verified_idx');
        });
        Schema::table('riders', function (Blueprint $table): void {
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('location_accuracy_meters')->nullable();
            $table->timestamp('location_updated_at')->nullable();
        });
        Schema::create('delivery_pricing_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('scope_key', 60)->unique();
            $table->foreignUlid('vendor_id')->nullable()->unique()->constrained('vendors');
            $table->string('pricing_method', 20)->default('per_km');
            foreach (['fixed_fee', 'base_fee', 'price_per_km', 'minimum_fee'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->unsignedInteger('maximum_distance_meters');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('delivery_pricing_rule_id')->constrained('delivery_pricing_rules');
            $table->string('name', 100);
            $table->unsignedInteger('maximum_distance_meters');
            $table->unsignedInteger('fee');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['delivery_pricing_rule_id', 'maximum_distance_meters'], 'delivery_zone_distance_unique');
        });
        $settings = DB::table('system_settings')->whereIn('key', ['delivery_base_fee', 'delivery_per_km'])->pluck('value', 'key');
        DB::table('delivery_pricing_rules')->insert(['id' => (string) Str::ulid(), 'scope_key' => 'global', 'pricing_method' => 'per_km', 'fixed_fee' => 0, 'base_fee' => (int) json_decode($settings->get('delivery_base_fee', json_encode(config('marketplace.delivery_base_fee'))), true), 'price_per_km' => (int) json_decode($settings->get('delivery_per_km', json_encode(config('marketplace.delivery_per_km'))), true), 'minimum_fee' => 0, 'maximum_distance_meters' => (int) config('maps.default_maximum_distance_meters'), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('delivery_pricing_rules');
        Schema::table('riders', fn (Blueprint $table) => $table->dropColumn(['last_latitude', 'last_longitude', 'location_accuracy_meters', 'location_updated_at']));
        Schema::table('vendor_locations', function (Blueprint $table): void {
            $table->dropIndex('locations_delivery_verified_idx');
            $table->dropConstrainedForeignId('location_verified_by');
            $table->dropColumn(['google_place_id', 'location_verification_status', 'location_verified_at']);
        });
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropUnique(['default_user_id']);
            $table->dropIndex(['user_id', 'is_default', 'deleted_at']);
            $table->dropColumn(['default_user_id', 'is_default', 'formatted_address', 'google_place_id', 'landmark', 'delivery_instructions']);
        });
    }
};
