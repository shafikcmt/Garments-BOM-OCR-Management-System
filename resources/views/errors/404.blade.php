@extends('errors.layout')
@section('code', '404')
@section('tone', 'is-neutral')
@section('title', 'That page does not exist')
@section('message', 'The link may be out of date, or the record it pointed to has since been removed.')
@section('detail')
    If you reached this from a bookmark, the file or record it referred to has probably been deleted.
@endsection
