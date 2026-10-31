@extends('emails.layouts.master')
@section('title', 'Review your Listing Studio purchase')
@section('preheader', 'Sign in to review the price and approve your purchase. No payment has been taken.')
@section('hero')
<h1>Review your Listing Studio purchase</h1>
@endsection
@section('content')
<p>Hi {{ $clientName }},</p>
<p>The RePro team has prepared a purchase for your account. Sign in to review the current price and confirm whether you want to continue.</p>
<p><a href="{{ $reviewUrl }}" class="button">Review purchase</a></p>
<p>No payment has been taken. Only you can approve a card payment from your account.</p>
<p>This request expires {{ $expiresAt }}. You can decline it from the review page.</p>
@endsection
