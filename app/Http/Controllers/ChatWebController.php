<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use Illuminate\Http\Request;

class ChatWebController extends Controller
{
    public function index()
    {
        $sessions = ChatSession::orderByDesc('updated_at')->limit(50)->get();
        return view('chat.index', compact('sessions'));
    }

    public function show(ChatSession $session)
    {
        $messages = $session->messages()->latest('id')->limit(100)->get()->reverse()->values();
        return view('chat.show', compact('session', 'messages'));
    }

    // Polling endpoint for live updates (session auth, no token juggling).
    public function tail(Request $request, ChatSession $session)
    {
        $after = (int) $request->query('after_id', 0);
        $rows = $session->messages()->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        return response()->json(['messages' => $rows]);
    }

    // Slash-command suggestions for the web input (also executable: the
    // bridge delivers them to pi as the next message).
    public function commands()
    {
        return response()->json(['commands' => [
            ['name' => '/share-chat-status', 'description' => 'Show bridge status in pi'],
            ['name' => '/unshare-chat', 'description' => 'Stop bridging this session'],
            ['name' => '/help', 'description' => 'List pi commands'],
        ]]);
    }

    public function send(Request $request, ChatSession $session)
    {
        if (! $session->is_open) {
            return back()->with('error', 'Session is closed.');
        }
        $data = $request->validate(['text' => 'required|string|max:20000']);
        $session->messages()->create([
            'user_id' => $request->user()->id,
            'role' => 'user',
            'text' => $data['text'],
            'via' => 'web',
        ]);
        $session->touch();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true], 201);
        }
        return back();
    }
}
