@extends('errors.layout')
@section('code', '500')
@section('tone', 'is-danger')
@section('icon')<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.01"/></svg>@endsection
@section('title', 'Something went wrong on our side')
@section('message', 'The error has been logged. Your data is not affected by this page failing to load.')
@section('detail')
    If it keeps happening, tell an administrator what you were doing and roughly when —
    that is enough to find it in the log.
@endsection
