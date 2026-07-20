@extends('errors.layout')
@section('code', '419')
@section('tone', 'is-warning')
@section('title', 'Your session expired')
@section('message', 'You were signed out because the page sat open too long. Nothing you had already saved is affected.')
@section('detail')
    Sign in again and repeat the last action. If you were part-way through a form,
    you will need to fill it in once more.
@endsection
