@extends('okta-oidc::layout')

@section('title', 'Session Expired')

@section('content')
    <h1>Session expired</h1>
    <p>{{ $message }}</p>
    <a class="btn"
        href="{{ $loginUrl }}">{{ config('app.name') ? 'Sign into ' . config('app.name') : 'Sign in again' }}</a>
@endsection
