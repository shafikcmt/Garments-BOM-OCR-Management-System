@extends('errors.layout')
@section('code', '503')
@section('tone', 'is-warning')
@section('icon')<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.6-.6-.6-2.6z"/></svg>@endsection
@section('title', 'The system is being updated')
@section('message', 'We are installing improvements. This usually takes only a few minutes and your saved data is safe.')
@section('status', 'Update in progress · this page refreshes automatically')
@section('reload', '1')
