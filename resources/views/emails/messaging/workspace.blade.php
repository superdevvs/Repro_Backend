@extends('emails.layouts.master')
@section('title', $subject)
@section('preheader', $preheaderText)
@section('content')
    {!! $bodyHtml !!}
@endsection
