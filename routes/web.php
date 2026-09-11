<?php

use App\Http\Controllers\ChatWebController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('chat.index')
    : redirect()->route('login'));

Route::get('/login', fn () => view('auth.login'))->name('login')->middleware('guest');
Route::post('/login', function (Request $request) {
    $cred = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
    if (Auth::attempt($cred, remember: true)) {
        $request->session()->regenerate();
        return redirect()->intended(route('chat.index'));
    }
    return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
})->middleware('guest');
Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/chat', [ChatWebController::class, 'index'])->name('chat.index');
    Route::get('/chat/{session}', [ChatWebController::class, 'show'])->name('chat.show');
    Route::get('/chat/{session}/tail', [ChatWebController::class, 'tail'])->name('chat.tail');
    Route::post('/chat/{session}/send', [ChatWebController::class, 'send'])->name('chat.send');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/tokens', [SettingsController::class, 'storeToken'])->name('settings.tokens.store');
    Route::delete('/settings/tokens/{id}', [SettingsController::class, 'destroyToken'])->name('settings.tokens.destroy');
});
