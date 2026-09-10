@extends('layout')
@section('title', 'login')
@section('content')
<div class="card" style="max-width:380px;margin:30px auto">
<h2 style="margin-top:0">Log in</h2>
@if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('login') }}">
@csrf
<p><input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required autofocus></p>
<p><input type="password" name="password" placeholder="Password" required></p>
<p style="margin-bottom:0"><button type="submit" style="width:100%">Log in</button></p>
</form>
</div>
@endsection
