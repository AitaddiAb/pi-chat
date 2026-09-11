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
.row{max-width:760px;margin:0 auto;display:flex;gap:8px;align-items:flex-end}
#in{flex:1;background:#0a0d13;color:var(--fg);border:1px solid var(--line);border-radius:10px;padding:9px 12px;font:inherit;resize:none;max-height:160px;line-height:1.45}
#cmds{display:none;max-width:760px;margin:0 auto 8px;background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
#cmds .cmd{padding:8px 12px;font-size:14px;cursor:pointer}
#cmds .cmd.on{background:#1a2230}
#cmds .cmd span{color:var(--dim);font-size:13px}
#status{font-size:12px;color:var(--dim);text-align:center;max-width:760px;margin:6px auto 0}
</style>
@endsection
@section('content')
<p><a href="{{ route('chat.index') }}">← sessions</a></p>
<h2 style="margin:4px 0 12px">{{ $session->title }} <span class="dim">{{ $session->is_open ? '🟢' : '⚪' }}</span></h2>
@if(session('error'))<div class="err">{{ session('error') }}</div>@endif
<div id="log"></div>
<form class="send" id="f"><div id="cmds"></div><div class="row">
<textarea id="in" rows="2" maxlength="20000" placeholder="Type a reply — Enter = new line, ⌘/Ctrl+Enter = send, / = commands…" {{ $session->is_open ? '' : 'disabled' }}></textarea>
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
fetch('{{ route('chat.send', $session) }}',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},body:JSON.stringify({text:v})}).then(function(r){if(!r.ok)throw 0;inp.value='';grow();}).catch(function(){alert('send failed');}).finally(function(){btn.disabled=false;inp.focus();});};
// Multi-line input: Enter = new line, Cmd/Ctrl+Enter = send.
function grow(){inp.style.height='auto';inp.style.height=Math.min(inp.scrollHeight,160)+'px';}
inp.addEventListener('input',function(){grow();updCmds();});
// Slash-command suggestions.
var cmdBox=document.getElementById('cmds'),cmds=[],sel=-1;
fetch('{{ route('chat.commands') }}').then(function(r){return r.json();}).then(function(s){cmds=s.commands||[];}).catch(function(){});
function hl(){for(var i=0;i<cmdBox.children.length;i++){cmdBox.children[i].className='cmd'+(i===sel?' on':'');}}
function pick(name){inp.value=name+' ';cmdBox.style.display='none';sel=-1;grow();inp.focus();}
function updCmds(){var v=inp.value;
if(v.charAt(0)!=='/'){cmdBox.style.display='none';sel=-1;return;}
var q=v.slice(1).split(/\s/)[0].toLowerCase();
var list=cmds.filter(function(c){return c.name.slice(1).toLowerCase().indexOf(q)===0;});
if(!list.length){cmdBox.style.display='none';sel=-1;return;}
cmdBox.innerHTML='';sel=0;
list.forEach(function(c,i){var d=document.createElement('div');d.className='cmd'+(i===0?' on':'');
d.innerHTML='<b></b> <span></span>';d.children[0].textContent=c.name;d.children[1].textContent=c.description;
d.onclick=function(){pick(c.name);};cmdBox.appendChild(d);});
cmdBox._list=list;cmdBox.style.display='block';}
inp.addEventListener('keydown',function(e){
if((e.metaKey||e.ctrlKey)&&e.key==='Enter'){e.preventDefault();form.requestSubmit();return;}
if(cmdBox.style.display!=='block')return;
if(e.key==='ArrowDown'){e.preventDefault();sel=(sel+1)%cmdBox.children.length;hl();}
else if(e.key==='ArrowUp'){e.preventDefault();sel=(sel-1+cmdBox.children.length)%cmdBox.children.length;hl();}
else if(e.key==='Enter'||e.key==='Tab'){if(sel>-1&&cmdBox._list&&cmdBox._list[sel]){e.preventDefault();pick(cmdBox._list[sel].name);}}
else if(e.key==='Escape'){cmdBox.style.display='none';sel=-1;}});
grow();
</script>
@endsection
