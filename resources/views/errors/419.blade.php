@extends('errors.layout')
@section('code', '419')
@section('tone', 'is-warning')
@section('icon')<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>@endsection
@section('title', 'Your session expired')
@section('message', 'You were signed out because the page sat open too long. Nothing you had already saved is affected.')
@section('detail')
    Sign in again and repeat the last action. If you were part-way through a form,
    you will need to fill it in once more.
@endsection
