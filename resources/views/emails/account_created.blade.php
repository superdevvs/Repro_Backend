@extends('emails.layouts.master')

@section('title', 'New Account Information')
@section('preheader', 'Your new R/E Pro Photos dashboard account is ready.')

@section('hero')
    <div class="eyebrow">Account Created</div>
    <h1 class="hero-title">Your dashboard access is ready.</h1>
    <p class="hero-copy">We created your R/E Pro Photos account so you can schedule shoots, track production, and manage billing in one place.</p>
@endsection

@section('content')
<p class="intro"><strong>Welcome to the R/E Pro Photos dashboard.</strong></p>

    <div class="button-row">
        <a href="{{ $resetLink }}" class="button">Create Password</a>
        <a href="https://reprodashboard.com" class="button button-secondary">Open Dashboard</a>
    </div>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Account Details</div>
            <div class="section-title">{{ $user->name }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                @if(!empty($user->company_name))
                    <tr>
                        <td class="detail-label">Company</td>
                        <td class="detail-value">{{ $user->company_name }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="detail-label">Email</td>
                    <td class="detail-value">{{ $user->email }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Phone</td>
                    <td class="detail-value">{{ $user->phonenumber ?? 'N/A' }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="callout">
        <div class="callout-title">Your next step</div>
        <p class="callout-copy">Use the secure link above to set your password. After that, you can log in anytime at reprodashboard.com to manage shoots and invoices.</p>
    </div>
@endsection

@section('footer_note')
    If you were not expecting this account, contact our team right away.
@endsection
