@extends('okta-oidc::layout')

@section('title', 'Sign-in Failed')

@section('content')
    <h1>Sign-in failed</h1>
    <p>{{ $message }}</p>
    <a class="btn"
        href="{{ $loginUrl }}">{{ config('app.name') ? 'Sign into ' . config('app.name') : 'Try signing in again' }}</a>
@endsection
