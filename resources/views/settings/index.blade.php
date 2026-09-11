@extends('layout')
@section('title', 'settings')
@section('content')
<h2 style="margin:4px 0 12px">⚙️ Settings</h2>

<div class="card">
<h3 style="margin-top:0">Bot / pi tokens</h3>
<p class="dim">Tokens act as this admin account for the API and the pi bridge.
Create one per device or pi project. The secret is shown <strong>once</strong> — copy it into <code>PI_CHAT_TOKEN</code>.</p>

@if(session('status'))<div class="card" style="border-color:var(--grn)">{{ session('status') }}</div>@endif
@if(isset($plain))
<div class="card" style="border-color:var(--grn)">
<strong>{{ $plainName }}</strong> created — copy it now, you won't see it again:
<br><code id="tok" style="font-size:16px;user-select:all">{{ $plain }}</code>
</div>
@endif

<form method="POST" action="{{ route('settings.tokens.store') }}" style="display:flex;gap:8px;margin-bottom:12px">
@csrf
<input name="name" placeholder="Token name, e.g. pi-bot" required pattern="[A-Za-z0-9_-]+" maxlength="64">
<button type="submit">Create token</button>
</form>
@if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

@forelse($tokens as $t)
<div class="card" style="display:flex;gap:10px;align-items:center">
<strong>{{ $t->name }}</strong>
<span class="dim">created {{ $t->created_at->diffForHumans() }} · last used {{ $t->last_used_at?->diffForHumans() ?? 'never' }}</span>
<form method="POST" action="{{ route('settings.tokens.destroy', $t->id) }}" style="margin-left:auto" onsubmit="return confirm('Revoke {{ $t->name }}? Pi/bridges using it stop working.')">
@csrf @method('DELETE')
<button type="submit" style="background:var(--red);color:#fff;padding:6px 12px;font-size:13px">Revoke</button>
</form>
</div>
@empty
<div class="card dim">No tokens yet — create one above.</div>
@endforelse
</div>
@endsection
