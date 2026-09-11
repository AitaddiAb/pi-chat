<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>pi chat · @yield('title', 'live')</title>
<style>
:root{--bg:#0b0e14;--panel:#11151f;--line:#1f2635;--txt:#e6e9f2;--dim:#8b93a7;--acc:#6ea8fe;--grn:#3dd68c;--amb:#ffb224;--red:#ff6b6b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.55 -apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,Segoe UI,Roboto,sans-serif}
header{position:sticky;top:0;z-index:5;background:rgba(11,14,20,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--line);padding:10px 14px;display:flex;gap:10px;align-items:center}
h1{font-size:14px;margin:0;font-weight:650}
main{max-width:760px;margin:0 auto;padding:14px}
a{color:var(--acc)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:0 0 10px}
input,textarea{width:100%;background:#161c2a;border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:11px 12px;font-size:15px;outline:none}
input:focus,textarea:focus{border-color:var(--acc)}
button{background:var(--acc);border:0;color:#06101f;font-weight:700;border-radius:10px;padding:10px 18px;font-size:15px;cursor:pointer}
button:disabled{opacity:.5}
.err{background:#2a1215;border:1px solid var(--red);color:#ffb3b3;border-radius:10px;padding:8px 12px;margin-bottom:10px}
.dim{color:var(--dim);font-size:13px}
.right{margin-left:auto;display:flex;gap:10px;align-items:center}
</style>
@yield('head')
</head>
<body>
<header>
<h1>🤖 pi chat</h1>
<div class="right">
@auth
<span class="dim">{{ auth()->user()->email }}</span>
@if(auth()->user()->is_admin)<a href="{{ route('settings.index') }}">⚙️</a>@endif
<form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" style="padding:6px 12px;font-size:13px">Out</button></form>
@endauth
</div>
</header>
<main>@yield('content')</main>
@yield('scripts')
</body>
</html>
