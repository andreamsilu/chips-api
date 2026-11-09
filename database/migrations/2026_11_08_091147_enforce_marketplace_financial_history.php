<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $historyTables = ['ledger_entries', 'payment_events', 'audit_logs', 'order_histories', 'order_items', 'settlement_entries', 'idempotency_records'];

    private array $snapshots = ['orders' => ['user_id', 'vendor_id', 'vendor_location_id', 'fulfilment', 'payment_method', 'currency', 'subtotal', 'discount', 'delivery_fee', 'commission_bps', 'commission', 'total', 'address_snapshot'], 'payments' => ['order_id', 'merchant_reference', 'amount', 'currency', 'phone', 'request_payload'], 'refunds' => ['order_id', 'payment_id', 'amount', 'reason', 'requested_by']];

    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        foreach ($this->historyTables as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_no_'.strtolower($operation);
                $statement = $mysql ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Append-only financial history'" : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Append-only financial history'); END";
                DB::unprepared($statement);
            }
        }
        foreach ($this->snapshots as $table => $columns) {
            $checks = array_map(fn (string $column): string => $mysql ? "NOT (NEW.{$column} <=> OLD.{$column})" : "NEW.{$column} IS NOT OLD.{$column}", $columns);
            $condition = implode(' OR ', $checks);
            DB::unprepared($mysql ? "CREATE TRIGGER {$table}_snapshot BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable financial snapshot'; END IF; END" : "CREATE TRIGGER {$table}_snapshot BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Immutable financial snapshot'); END");
            DB::unprepared($mysql ? "CREATE TRIGGER {$table}_retain BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial records must be retained'" : "CREATE TRIGGER {$table}_retain BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Financial records must be retained'); END");
        }
    }

    public function down(): void
    {
        foreach ($this->historyTables as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$operation}");
            }
        }foreach (array_keys($this->snapshots) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_snapshot");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_retain");
        }
    }
};
