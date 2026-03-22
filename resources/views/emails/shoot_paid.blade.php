@extends('emails.layouts.master')

@section('title', 'Your Shoot Has Been Marked as Paid')
@section('preheader', 'This shoot has now been marked paid.')

@section('hero')
    <div class="eyebrow">Paid in Full</div>
    <h1 class="hero-title">This shoot has been marked as paid.</h1>
    <p class="hero-copy">Your account now reflects a paid status for the appointment below, and any delivery access tied to payment can proceed normally.</p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}, <strong>good news: this shoot is now marked paid.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Payment Update</div>
            <div class="section-title">{{ '$' . number_format($amount, 2) }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <td class="detail-label">Recorded on</td>
                    <td class="detail-value">{{ now()->format('M j, Y') }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Payment status</td>
                    <td class="detail-value">Paid</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button">Open Dashboard</a>
    </div>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot])
@endsection

@section('footer_note')
    Final images and downloads are available according to the current dashboard status for this shoot.
@endsection
