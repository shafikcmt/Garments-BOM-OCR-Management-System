@extends('errors.layout')
@section('code', '404')
@section('tone', 'is-neutral')
@section('icon')<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>@endsection
@section('title', 'That page does not exist')
@section('message', 'The link may be out of date, or the record it pointed to has since been removed.')
@section('detail')
    If you reached this from a bookmark, the file or record it referred to has probably been deleted.
@endsection
