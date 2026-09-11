@extends('layout')
@section('title', 'sessions')
@section('content')
<h2 style="margin:4px 0 12px">Sessions</h2>
@forelse($sessions as $s)
<a href="{{ route('chat.show', $s) }}" style="text-decoration:none;color:inherit">
<div class="card">
<strong>{{ $s->title }}</strong>
<span class="dim">· #{{ $s->id }} · {{ $s->key }}@if($s->pi_session_id) · pi {{ substr($s->pi_session_id, 0, 8) }}@endif · {{ $s->is_open ? '🟢 open' : '⚪ closed' }} · updated {{ $s->updated_at->diffForHumans() }}</span>
</div>
</a>
@empty
<div class="card dim">No sessions yet — run <code>/share-chat</code> in pi and one appears here.</div>
@endforelse
@endsection
