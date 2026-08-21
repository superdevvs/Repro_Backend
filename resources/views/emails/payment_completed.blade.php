@extends('emails.layouts.master')

@section('title', 'Shoot Balance Paid in Full')
@section('preheader', 'The remaining balance for this shoot is now paid in full.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Balance Complete</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">This shoot is paid in full.</p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;">No balance remains for this shoot. Your separate payment receipt contains the transaction details for your records.</p>
    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'showFinancials' => false])
@endsection

@section('footer_note')
    You can review the shoot and its payment history in the dashboard.
@endsection
