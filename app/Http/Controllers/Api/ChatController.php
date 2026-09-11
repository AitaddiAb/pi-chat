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
        return ChatSession::orderByDesc('updated_at')->limit(50)->get(['id', 'key', 'pi_session_id', 'title', 'is_open', 'updated_at']);
    }

    // Pi calls this on start: 1 web chat per pi session (idempotent).
    // - pi_session_id present → find by it (resume = same chat), else create.
    // - legacy (no pi_session_id) → fall back to stable thread by key.
    public function claim(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|max:64',
            'title' => 'nullable|string|max:120',
            'pi_session_id' => 'nullable|string|max:64',
            'fresh' => 'sometimes|boolean',
        ]);

        if (! empty($data['pi_session_id'])) {
            if (! empty($data['fresh'])) {
                // Force a brand-new web chat for this pi session: detach the
                // old mapping (history stays) then create fresh.
                ChatSession::where('pi_session_id', $data['pi_session_id'])->update(['pi_session_id' => null]);
                $session = ChatSession::create([
                    'key' => $data['key'],
                    'title' => $data['title'] ?? $data['key'],
                    'pi_session_id' => $data['pi_session_id'],
                    'is_open' => true,
                ]);

                return response()->json($session->only('id', 'key', 'pi_session_id', 'title', 'is_open'));
            }
            $session = ChatSession::firstOrCreate(
                ['pi_session_id' => $data['pi_session_id']],
                ['key' => $data['key'], 'title' => $data['title'] ?? $data['key']]
            );
            $session->update([
                'is_open' => true,
                'key' => $data['key'],
                'title' => $data['title'] ?? $session->title,
            ]);

            return response()->json($session->only('id', 'key', 'pi_session_id', 'title', 'is_open'));
        }

        $session = ChatSession::firstOrCreate(
            ['key' => $data['key']],
            ['title' => $data['title'] ?? $data['key']]
        );
        $session->update(['is_open' => true, 'title' => $data['title'] ?? $session->title]);

        return response()->json($session->only('id', 'key', 'pi_session_id', 'title', 'is_open'));
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
            'session' => $session->only('id', 'key', 'pi_session_id', 'title', 'is_open'),
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
