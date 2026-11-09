<?php

namespace App\Modules\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController extends Controller
{
    public function __invoke(Request $request, WebhookService $service): JsonResponse
    {
        $service->receive($request->getContent(), $request->header('X-Webhook-Timestamp'), $request->header('X-Webhook-Signature'));

        return response()->json(['received' => true]);
    }
}
