@extends('errors.layout')
@section('code', '403')
@section('tone', 'is-warning')
@section('title', 'You do not have access to this')
@section('message', 'Your role does not cover this screen, or the file you tried to open is locked.')
@section('detail')
    {{-- The two things that actually cause a 403 here, so the user can tell an
         admin which one it is instead of just "it says 403". --}}
    This is usually one of two things: the screen belongs to another department,
    or an admin has locked the BOM file while it is being worked on.
    Ask an administrator to check your role or the file lock.
@endsection
