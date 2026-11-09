<?php

namespace App\Modules\Engagement;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Support\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query();
        if (! $request->user()->hasRole('support') && ! $request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json($query->orderByDesc('id')->paginate(20, ['id', 'order_id', 'subject', 'status', 'created_at']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:5000'], 'order_id' => ['nullable', 'ulid']]);
        if (! empty($data['order_id'])) {
            Order::where('user_id', $request->user()->id)->findOrFail($data['order_id']);
        }
        $ticket = DB::transaction(function () use ($request, $data): SupportTicket {
            $ticket = SupportTicket::record(['user_id' => $request->user()->id, 'order_id' => $data['order_id'] ?? null, 'subject' => $data['subject']]);
            SupportMessage::record(['support_ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);

            return $ticket;
        });

        return response()->json(['data' => $ticket->only(['id', 'subject', 'status'])], 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);

        return response()->json(['data' => $ticket->only(['id', 'order_id', 'subject', 'status']), 'messages' => $ticket->messages()->orderBy('id')->paginate(30, ['id', 'body', 'created_at'])]);
    }

    public function message(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        Domain::require($ticket->status === 'open', 'Ticket is closed.');
        $message = SupportMessage::record(['support_ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);

        return response()->json(['data' => $message->only(['id', 'body', 'created_at'])], 201);
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        $ticket->forceFill(['status' => 'closed'])->save();
        Domain::audit($request->user(), 'support.closed', $ticket);

        return response()->json(['message' => 'Ticket closed.']);
    }

    private function authorizeTicket(Request $request, SupportTicket $ticket): void
    {
        abort_unless(Gate::allows('view', $ticket), 404);
    }
}
