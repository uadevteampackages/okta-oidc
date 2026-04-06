@extends('okta-oidc::layout')

@section('title', 'Signed Out')

@section('content')
    <h1>Signed out</h1>
    <p>{{ $message }}</p>
    <a class="btn" href="{{ $loginUrl }}">{{ config('app.name') ? 'Sign into ' . config('app.name') : 'Sign in' }}</a>
@endsection
