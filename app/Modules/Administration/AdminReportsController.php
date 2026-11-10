<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminReportsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $data = $this->period($request);
        $report = Cache::remember('admin-overview:'.hash('sha256', json_encode($data)), 20, function () use ($data): array {
            $vendors = Vendor::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $orders = AdminQuery::dates(Order::query(), $data);
            $statuses = (clone $orders)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $today = CarbonImmutable::now('Africa/Dar_es_Salaam')->startOfDay()->utc();

            return ['generated_at' => now()->toISOString(), 'date_from' => $data['date_from'], 'date_to' => $data['date_to'], 'currency' => 'TZS', 'totals' => [
                'customers' => User::whereHas('roles', fn (Builder $q) => $q->where('name', 'customer'))->count(),
                'vendors' => (int) $vendors->sum(), 'active_vendors' => (int) $vendors->get('approved', 0), 'pending_vendors' => (int) $vendors->get('pending', 0),
                'orders' => (clone $orders)->count(), 'today_orders' => Order::where('created_at', '>=', $today)->count(),
                'active_orders' => (clone $orders)->whereIn('status', ['confirmed', 'accepted', 'preparing', 'ready', 'out_for_delivery'])->count(),
                'riders' => Rider::count(), 'active_riders' => Rider::where('status', 'approved')->where('available', true)->whereHas('user', fn (Builder $q) => $q->whereNull('suspended_at'))->count(),
            ], 'orders_by_status' => (object) $statuses->all(), 'finance' => $this->summary($data), 'trend' => $this->trend($data),
                'recent_orders' => Order::with('vendor')->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()->map(fn (Order $order) => $order->only(['id', 'status', 'payment_status', 'fulfilment', 'total', 'created_at']) + ['vendor_name' => $order->vendor?->name]),
                'recent_transactions' => Payment::orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['id', 'order_id', 'status', 'amount', 'currency', 'created_at'])->map(fn (Payment $payment) => $payment->only(['id', 'order_id', 'status', 'amount', 'currency', 'created_at'])),
            ];
        });

        return response()->json(['data' => $report]);
    }

    public function finance(Request $request): JsonResponse
    {
        $data = $this->period($request, true);

        return response()->json(['data' => ['date_from' => $data['date_from'], 'date_to' => $data['date_to'], 'currency' => 'TZS', 'generated_at' => now()->toISOString(), ...$this->summary($data), 'trend' => $this->trend($data)]]);
    }

    public function ledger(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, LedgerEntry::query(), ['ledger_entries.id', 'order_id', 'vendor_id', 'account', 'kind'], ['vendor_id' => AdminQuery::identifier(), 'order_id' => AdminQuery::identifier(), 'account' => AdminQuery::enum(['provider_clearing', 'vendor_payable', 'platform_commission', 'delivery_revenue']), 'kind' => AdminQuery::enum(['capture', 'refund', 'refund_reversed', 'manual_refund'])], ['created_at' => 'ledger_entries.created_at', 'id' => 'ledger_entries.id', 'amount' => 'ledger_entries.amount']));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->period($request, true);
        $query = $this->ledgerQuery($data);
        if ((clone $query)->count() > 100000) {
            throw ValidationException::withMessages(['date_from' => 'Narrow the period to export at most 100,000 ledger entries.']);
        }
        $lastId = (clone $query)->max('id');
        $query->where('id', '<=', $lastId ?? '');
        AuditLog::record(['actor_id' => $request->user()->id, 'action' => 'finance.exported', 'subject_type' => 'Report', 'subject_id' => 'ledger', 'metadata' => $data]);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['id', 'order_id', 'vendor_id', 'account', 'kind', 'amount', 'currency', 'created_at'], escape: '');
            foreach ($query->lazyById(500) as $entry) {
                fputcsv($output, [$entry->id, $entry->order_id, $entry->vendor_id, $entry->account, $entry->kind, (int) $entry->amount, $entry->currency, $entry->created_at->toISOString()], escape: '');
            }
            fclose($output);
        }, 'mloexpress-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function period(Request $request, bool $vendor = false): array
    {
        $rules = ['date_from' => ['sometimes', 'required', 'date_format:Y-m-d'], 'date_to' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:date_from']];
        if ($vendor) {
            $rules['vendor_id'] = ['sometimes', 'ulid'];
        }
        $unknown = array_diff(array_keys($request->query()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => 'This report filter is not supported.']);
        }
        $input = $request->query() + ['date_from' => CarbonImmutable::now('Africa/Dar_es_Salaam')->subDays(29)->toDateString(), 'date_to' => CarbonImmutable::now('Africa/Dar_es_Salaam')->toDateString()];
        $data = validator($input, $rules)->validate();
        $days = CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to']), false);
        if ($days < 0 || $days > 365) {
            throw ValidationException::withMessages(['date_to' => 'Reports require an ordered period of at most 366 days.']);
        }

        return $data;
    }

    /** @return Builder<LedgerEntry> */
    private function ledgerQuery(array $data): Builder
    {
        $query = AdminQuery::dates(LedgerEntry::query(), $data);
        if (isset($data['vendor_id'])) {
            $query->where('ledger_entries.vendor_id', $data['vendor_id']);
        }

        return $query;
    }

    private function summary(array $data): array
    {
        $query = $this->ledgerQuery($data);
        $balances = (clone $query)->selectRaw('account, sum(amount) as balance')->groupBy('account')->pluck('balance', 'account');
        $captures = (clone $query)->where('ledger_entries.account', 'platform_commission')->where('kind', 'capture')->join('orders', 'orders.id', '=', 'ledger_entries.order_id')->sum('orders.total');
        $refunds = (clone $query)->where('ledger_entries.account', 'platform_commission')->whereIn('kind', ['refund', 'manual_refund', 'refund_reversed'])->join('orders', 'orders.id', '=', 'ledger_entries.order_id')->selectRaw("COALESCE(SUM(CASE WHEN ledger_entries.kind = 'refund_reversed' THEN -orders.total ELSE orders.total END), 0) as total")->value('total');

        return ['gross_collections' => (int) $captures, 'net_refunds' => (int) $refunds, 'net_collections' => (int) $captures - (int) $refunds, 'net_commission' => (int) $balances->get('platform_commission', 0), 'delivery_revenue' => (int) $balances->get('delivery_revenue', 0), 'platform_revenue' => (int) $balances->get('platform_commission', 0) + (int) $balances->get('delivery_revenue', 0), 'vendor_payable' => (int) $balances->get('vendor_payable', 0)];
    }

    private function trend(array $data): array
    {
        $expression = DB::getDriverName() === 'sqlite' ? "date(created_at, '+3 hours')" : 'DATE(DATE_ADD(created_at, INTERVAL 3 HOUR))';
        $rows = $this->ledgerQuery($data)->whereIn('account', ['platform_commission', 'delivery_revenue'])->selectRaw($expression.' as day, sum(amount) as revenue')->groupByRaw($expression)->pluck('revenue', 'day');
        $orders = AdminQuery::dates(Order::query(), $data);
        if (isset($data['vendor_id'])) {
            $orders->where('vendor_id', $data['vendor_id']);
        }
        $counts = $orders->selectRaw($expression.' as day, count(*) as total')->groupByRaw($expression)->pluck('total', 'day');
        $trend = [];
        for ($day = CarbonImmutable::parse($data['date_from']); $day->toDateString() <= $data['date_to']; $day = $day->addDay()) {
            $key = $day->toDateString();
            $trend[] = ['date' => $key, 'orders' => (int) $counts->get($key, 0), 'platform_revenue' => (int) $rows->get($key, 0)];
        }

        return $trend;
    }
}
