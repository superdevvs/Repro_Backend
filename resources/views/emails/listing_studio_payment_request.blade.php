@extends('emails.layouts.master')
@section('title', 'Review your Listing Studio purchase')
@section('preheader', 'Sign in to review your purchase and its current status.')
@section('hero')
<h1>Review your Listing Studio purchase</h1>
@endsection
@section('content')
<p>Hi {{ $clientName }},</p>
<p>The RePro team has prepared a purchase for your account. Sign in to review the current price and confirm whether you want to continue.</p>
<p><a href="{{ $reviewUrl }}" class="button">Review purchase</a></p>
<p>This email does not charge your card. Only you can approve a card payment from your account. If you already completed this purchase, the review page will show its current status.</p>
<p>This request expires {{ $expiresAt }}. You can decline it from the review page.</p>
@endsection
