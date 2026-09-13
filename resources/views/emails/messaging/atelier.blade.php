@extends('emails.layouts.master')
@section('title', $subject)
@section('preheader', $preheaderText)
@section('hero')
    <p class="dark-muted" style="margin:0 0 16px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;">{{ $emailAtelier['eyebrow'] ?? 'A NOTE FROM REPRO' }}</p>
    <h1 class="hero-title-td dark-title" style="margin:0;font-size:36px;line-height:42px;font-weight:500;letter-spacing:-1.2px;">{!! $heroTitleHtml !!}</h1>
    @if(!empty($heroCopy))
        <p class="dark-body" style="margin:16px 0 0;font-size:16px;line-height:26px;">{{ $heroCopy }}</p>
    @endif
@endsection
@section('content')
    {!! $bodyHtml !!}
@endsection
@section('footer_note')
    {!! $emailFooterNote ?? '' !!}
@endsection
