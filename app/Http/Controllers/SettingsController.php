<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private function adminOrFail(Request $request)
    {
        abort_unless($request->user()?->is_admin, 403, 'Admins only.');
    }

    public function index(Request $request)
    {
        $this->adminOrFail($request);
        $tokens = $request->user()->tokens()->latest()->get(['id', 'name', 'last_used_at', 'created_at']);
        $plain = $request->session()->pull('new_token_plain');
        $plainName = $request->session()->pull('new_token_name');
        return view('settings.index', compact('tokens', 'plain', 'plainName'));
    }

    // Create a bot/pi token — plaintext shown ONCE, like an API key.
    public function storeToken(Request $request)
    {
        $this->adminOrFail($request);
        $data = $request->validate(['name' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/']);
        $token = $request->user()->createToken($data['name']);
        return redirect()->route('settings.index')->with([
            'new_token_plain' => $token->plainTextToken,
            'new_token_name' => $data['name'],
        ]);
    }

    public function destroyToken(Request $request, int $id)
    {
        $this->adminOrFail($request);
        $request->user()->tokens()->where('id', $id)->delete();
        return redirect()->route('settings.index')->with('status', 'Token revoked.');
    }
}
