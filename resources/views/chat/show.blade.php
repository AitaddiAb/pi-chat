@extends('layout')
@section('title', $session->title)
@section('head')
<style>
main{padding-bottom:120px}
.msg{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin:0 0 10px;overflow-wrap:anywhere}
.msg .who{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--dim);margin-bottom:4px}
.msg.user{border-left:3px solid var(--acc)}.msg.assistant{border-left:3px solid var(--grn)}
.msg.tool{border-left:3px solid var(--amb);font-size:13.5px}.msg.status{border-style:dashed;background:transparent;color:var(--dim);font-size:13px}
.body{white-space:pre-wrap}
pre{background:#0a0d13;border:1px solid var(--line);border-radius:8px;padding:8px 10px;overflow:auto;font-size:12.5px}
code{font-family:ui-monospace,Menlo,monospace;background:#0a0d13;padding:1px 5px;border-radius:5px;font-size:13px}
pre code{background:none;padding:0}
form.send{position:fixed;left:0;right:0;bottom:0;background:rgba(11,14,20,.95);border-top:1px solid var(--line);padding:10px 12px calc(10px + env(safe-area-inset-bottom))}
.row{max-width:760px;margin:0 auto;display:flex;gap:8px}
#status{font-size:12px;color:var(--dim);text-align:center;max-width:760px;margin:6px auto 0}
</style>
@endsection
@section('content')
<p><a href="{{ route('chat.index') }}">← sessions</a></p>
<h2 style="margin:4px 0 12px">{{ $session->title }} <span class="dim">{{ $session->is_open ? '🟢' : '⚪' }}</span></h2>
@if(session('error'))<div class="err">{{ session('error') }}</div>@endif
<div id="log"></div>
<form class="send" id="f"><div class="row">
<input id="in" autocomplete="off" maxlength="20000" placeholder="Type a reply — local pi will run it…" {{ $session->is_open ? '' : 'disabled' }}>
<button id="btn" {{ $session->is_open ? '' : 'disabled' }}>Send</button>
</div><div id="status">live</div></form>
@endsection
@section('scripts')
<script>
var log=document.getElementById('log'),inp=document.getElementById('in'),form=document.getElementById('f'),btn=document.getElementById('btn'),st=document.getElementById('status');
var seen={},lastId=0,nearBottom=true;
addEventListener('scroll',function(){nearBottom=(innerHeight+scrollY)>document.body.scrollHeight-220;});
function md(s){s=s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
s=s.replace(/```([\s\S]*?)```/g,function(m,c){return '<pre><code>'+c.replace(/^\n/,'')+'</code></pre>';});
return s.replace(/`([^`]+)`/g,'<code>$1</code>').replace(/\n/g,'<br>');}
function who(e){if(e.role==='user')return '🧑 you'+(e.via==='web'?' ·web':'');
if(e.role==='assistant')return '🤖 pi';if(e.role==='tool')return '🔧 '+(e.tool_name||'tool');return '·';}
function render(e){if(seen[e.id])return;seen[e.id]=1;lastId=Math.max(lastId,e.id);
var d=document.createElement('div');d.className='msg '+e.role;
d.innerHTML='<div class="who">'+who(e)+'</div><div class="body">'+md(e.text||'')+'</div>';
log.appendChild(d);if(nearBottom)scrollTo(0,document.body.scrollHeight);}
@foreach($messages as $m)
render({id:{{ $m->id }},role:@json($m->role),text:@json($m->text),tool_name:@json($m->tool_name),via:@json($m->via)});
@endforeach
scrollTo(0,document.body.scrollHeight);
setInterval(function(){
fetch('{{ route('chat.tail', $session) }}?after_id='+lastId).then(function(r){return r.json();}).then(function(s){
st.textContent='live · '+new Date().toLocaleTimeString();
(s.messages||[]).forEach(render);
}).catch(function(){st.textContent='reconnecting…';});
},2000);
form.onsubmit=function(ev){ev.preventDefault();var v=inp.value.trim();if(!v)return;btn.disabled=true;
fetch('{{ route('chat.send', $session) }}',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},body:JSON.stringify({text:v})}).then(function(r){if(!r.ok)throw 0;inp.value='';}).catch(function(){alert('send failed');}).finally(function(){btn.disabled=false;inp.focus();});};
</script>
@endsection
