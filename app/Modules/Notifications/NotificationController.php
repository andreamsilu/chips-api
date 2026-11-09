<?php

namespace App\Modules\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->notifications()->orderByDesc('id')->paginate(30, ['id', 'type', 'data', 'read_at', 'created_at']));
    }

    public function update(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return response()->json(['message' => 'Notification read.']);
    }
}
