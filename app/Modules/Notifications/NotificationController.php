<?php

namespace App\Modules\Notifications;

use App\Http\Controllers\Controller;
use App\Modules\Administration\AdminQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(AdminQuery::page($request, $request->user()->notifications()->getQuery(), ['type', 'data'], ['status' => ['rules' => ['sometimes', Rule::in(['read', 'unread'])], 'apply' => fn ($q, string $status) => $status === 'read' ? $q->whereNotNull('read_at') : $q->whereNull('read_at')]], [], fn ($notification) => $notification->only(['id', 'type', 'data', 'read_at', 'created_at'])));
    }

    public function update(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return response()->json(['message' => 'Notification read.']);
    }
}
