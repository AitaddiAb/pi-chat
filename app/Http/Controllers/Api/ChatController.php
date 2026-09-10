<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function sessions()
    {
        return ChatSession::orderByDesc('updated_at')->limit(50)->get(['id', 'key', 'title', 'is_open', 'updated_at']);
    }

    // Pi calls this on start: claim a stable thread by key (idempotent).
    public function claim(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|max:64',
            'title' => 'nullable|string|max:120',
        ]);

        $session = ChatSession::firstOrCreate(
            ['key' => $data['key']],
            ['title' => $data['title'] ?? $data['key']]
        );
        $session->update(['is_open' => true, 'title' => $data['title'] ?? $session->title]);

        return response()->json($session->only('id', 'key', 'title', 'is_open'));
    }

    // Paginated-tail read. `after_id` = only rows the client hasn't seen (for polling).
    public function messages(Request $request, ChatSession $session)
    {
        $after = (int) $request->query('after_id', 0);
        $limit = min(200, max(1, (int) $request->query('limit', 100)));

        $q = $session->messages()->with('session:id')->where('id', '>', $after)->orderBy('id');
        // First load (after_id=0): just the tail.
        if ($after === 0) {
            $ids = (clone $q)->latest('id')->limit($limit)->pluck('id');
            $rows = $session->messages()->whereIn('id', $ids)->orderBy('id')->get();
        } else {
            $rows = $q->limit($limit)->get();
        }

        return response()->json([
            'session' => $session->only('id', 'key', 'title', 'is_open'),
            'messages' => $rows->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'text' => $m->text,
                'tool_name' => $m->tool_name,
                'via' => $m->via,
                'is_error' => $m->is_error,
                'user_id' => $m->user_id,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, ChatSession $session)
    {
        if (! $session->is_open) {
            return response()->json(['message' => 'Session is closed.'], 423);
        }

        $data = $request->validate([
            'role' => 'required|in:user,assistant,tool,status',
            'text' => 'required|string|max:20000',
            'tool_name' => 'nullable|string|max:64',
            'via' => 'nullable|string|max:16',
            'is_error' => 'sometimes|boolean',
            'meta' => 'nullable|array',
        ]);

        $msg = $session->messages()->create([
            'user_id' => $request->user()->id,
            'role' => $data['role'],
            'text' => $data['text'],
            'tool_name' => $data['tool_name'] ?? null,
            'via' => $data['via'] ?? null,
            'is_error' => $data['is_error'] ?? false,
            'meta' => $data['meta'] ?? null,
        ]);
        $session->touch();

        return response()->json(['id' => $msg->id], 201);
    }
}
