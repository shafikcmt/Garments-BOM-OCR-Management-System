@extends('errors.layout')
@section('code', '500')
@section('tone', 'is-danger')
@section('title', 'Something went wrong on our side')
@section('message', 'The error has been logged. Your data is not affected by this page failing to load.')
@section('detail')
    If it keeps happening, tell an administrator what you were doing and roughly when —
    that is enough to find it in the log.
@endsection
