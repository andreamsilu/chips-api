<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Refund;
use App\Models\Rider;
use App\Models\Role;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vendor;
use App\Modules\Delivery\DeliveryService;
use App\Modules\Finance\SettlementService;
use App\Modules\Payments\RefundService;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminController extends Controller
{
    public function vendors(Request $request): JsonResponse
    {
        Gate::authorize('administer');

        return response()->json(AdminQuery::page($request, Vendor::withCount(['locations', 'orders', 'documents']), ['vendors.id', 'name'], ['status' => AdminQuery::enum(['pending', 'approved', 'rejected', 'suspended'])], ['created_at' => 'vendors.created_at', 'id' => 'vendors.id', 'name' => 'vendors.name', 'status' => 'vendors.status', 'commission_bps' => 'vendors.commission_bps']));
    }

    public function approve(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('administer');
        $data = $request->validate(['status' => ['required', 'in:approved,rejected,suspended'], 'commission_bps' => ['required', 'integer', 'between:0,10000']]);
        DB::transaction(function () use ($vendor, $request, $data): void {
            $vendor = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $vendor->forceFill($data + ['approved_by' => $request->user()->id, 'approved_at' => now()])->save();
            Domain::audit($request->user(), 'vendor.reviewed', $vendor, $data);
        });

        return response()->json(['data' => $vendor->fresh()]);
    }

    public function role(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_guest, 404);
        Gate::authorize('administer');
        $data = $request->validate(['role' => ['required', 'in:customer,vendor,rider,support,admin']]);
        DB::transaction(function () use ($request, $user, $data): void {
            $user->roles()->syncWithoutDetaching([Role::where('name', $data['role'])->value('id')]);
            AuditLog::record(['actor_id' => $request->user()->id, 'action' => 'role.granted', 'subject_type' => 'User', 'subject_id' => $user->public_id, 'metadata' => $data]);
        });

        return response()->json(['message' => 'Role granted.']);
    }

    public function rider(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_guest, 404);
        Gate::authorize('administer');
        $data = $request->validate(['vehicle' => ['required', 'string', 'max:100']]);
        $rider = DB::transaction(function () use ($user, $data, $request): Rider {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Domain::require(! Rider::where('user_id', $user->id)->exists(), 'Rider is already registered.');
            $rider = Rider::record($data + ['user_id' => $user->id, 'status' => 'approved']);
            $user->roles()->syncWithoutDetaching([Role::where('name', 'rider')->value('id')]);
            Domain::audit($request->user(), 'rider.approved', $rider);

            return $rider;
        });

        return response()->json(['data' => $rider->only(['id', 'vehicle', 'status'])], 201);
    }

    public function assign(Request $request, Delivery $delivery, DeliveryService $service): JsonResponse
    {
        Gate::authorize('administer');
        $data = $request->validate(['rider_id' => ['required', 'ulid']]);

        return response()->json(['data' => $service->assign($delivery, Rider::findOrFail($data['rider_id']), $request->user())->only(['id', 'order_id', 'rider_id', 'status'])]);
    }

    public function refunds(Request $request): JsonResponse
    {
        Gate::authorize('administer');

        return response()->json(AdminQuery::page($request, Refund::query(), ['refunds.id', 'order_id', 'provider_reference', 'external_receipt', 'reason'], ['status' => AdminQuery::enum(['requested', 'approved', 'manual_required', 'refunded', 'failed', 'reversed']), 'order_id' => AdminQuery::identifier()], ['created_at' => 'refunds.created_at', 'id' => 'refunds.id', 'amount' => 'refunds.amount', 'status' => 'refunds.status']));
    }

    public function refund(Request $request, Refund $refund, RefundService $service): JsonResponse
    {
        Gate::authorize('administer');
        $data = $request->validate(['external_receipt' => ['nullable', 'string', 'min:8', 'max:160'], 'receipt_verified' => ['exclude_without:external_receipt', 'required', 'accepted']]);

        return response()->json(['data' => $service->approve($refund, $request->user(), $data['external_receipt'] ?? null)]);
    }

    public function settlements(Request $request): JsonResponse
    {
        Gate::authorize('administer');

        return response()->json(AdminQuery::page($request, Settlement::withCount('entries'), ['settlements.id', 'vendor_id', 'external_reference'], ['status' => AdminQuery::enum(['prepared', 'paid', 'voided']), 'vendor_id' => AdminQuery::identifier()], ['created_at' => 'settlements.created_at', 'id' => 'settlements.id', 'amount' => 'settlements.amount', 'status' => 'settlements.status']));
    }

    public function prepare(Request $request, Vendor $vendor, SettlementService $service): JsonResponse
    {
        Gate::authorize('administer');

        return response()->json(['data' => $service->prepare($vendor, $request->user())], 201);
    }

    public function paid(Request $request, Settlement $settlement, SettlementService $service): JsonResponse
    {
        Gate::authorize('administer');
        $data = $request->validate(['external_reference' => ['required', 'string', 'min:8', 'max:160'], 'transfer_verified' => ['required', 'accepted']]);

        return response()->json(['data' => $service->paid($settlement, $request->user(), $data['external_reference'])]);
    }

    public function audit(Request $request): JsonResponse
    {
        Gate::authorize('administer');

        return response()->json(AdminQuery::page($request, AuditLog::query(), ['audit_logs.id', 'action', 'subject_id', 'subject_type'], ['action' => ['rules' => ['sometimes', 'string', 'max:80']], 'subject_type' => ['rules' => ['sometimes', 'string', 'max:80']], 'subject_id' => ['rules' => ['sometimes', 'string', 'max:80']], 'actor_id' => AdminQuery::identifier() + ['apply' => fn ($q, string $id) => $q->whereIn('actor_id', User::where('public_id', $id)->select('id'))]]));
    }

    public function void(Request $request, Settlement $settlement, SettlementService $service): JsonResponse
    {
        Gate::authorize('administer');

        return response()->json(['data' => $service->void($settlement, $request->user())]);
    }
}
