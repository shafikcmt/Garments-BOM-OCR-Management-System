@extends('errors.layout')
@section('code', '403')
@section('tone', 'is-warning')
@section('icon')<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>@endsection
@section('title', 'You do not have access to this')
@section('message', 'Your role does not cover this screen, or the file you tried to open is locked.')
@section('detail')
    {{-- The two things that actually cause a 403 here, so the user can tell an
         admin which one it is instead of just "it says 403". --}}
    This is usually one of two things: the screen belongs to another department,
    or an admin has locked the BOM file while it is being worked on.
    Ask an administrator to check your role or the file lock.
@endsection
