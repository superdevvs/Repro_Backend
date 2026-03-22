@extends('emails.layouts.master')

@section('title', 'Terms/Conditions Accepted')
@section('preheader', 'A record of the terms and conditions accepted for your account.')

@section('hero')
    <div class="eyebrow">Terms Accepted</div>
    <h1 class="hero-title">Your terms acceptance has been recorded.</h1>
    <p class="hero-copy">This email keeps a copy of the current terms and conditions for your records, along with a quick summary of the most important points.</p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}, <strong>thank you for accepting the terms and conditions.</strong></p>

    <table class="stats-row" role="presentation">
        <tr>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Payment</div>
                    <div class="stat-copy">Payment is due in full at the time of booking.</div>
                </div>
            </td>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Changes</div>
                    <div class="stat-copy">Changes or cancellations close to the appointment may incur fees.</div>
                </div>
            </td>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Usage Rights</div>
                    <div class="stat-copy">Usage rights are granted after full payment is received.</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Full Agreement</div>
            <div class="section-title">Terms and conditions</div>
            <div class="body-copy">
                <p><strong>Property.</strong> The Property is the real property identified in your agreement with R/E Pro Photos. You certify that you are authorized to engage our services for that property and to accept these terms on behalf of the owner if applicable.</p>
                <p><strong>Payment.</strong> Payment is due in full at the time of booking. Usage rights are not granted until full payment is received.</p>
                <p><strong>Changes and cancellations.</strong> Shoot dates or times may be changed or cancelled without penalty up to twenty-four (24) hours before the scheduled time. Late changes, cancellations, or camera-unready properties may result in additional fees.</p>
                <p><strong>Grant of rights.</strong> You grant R/E Pro Photos a non-exclusive, perpetual, transferable, sublicensable worldwide right to create, reproduce, display, transmit, and distribute the work and derivative works through any media now known or later developed.</p>
                <p><strong>Usage rights.</strong> In exchange, we grant you a non-exclusive, non-transferable right to use the media for marketing the property, your real estate business, the listing, and your brokerage, including MLS and portal distribution while the listing remains active.</p>
                <p><strong>Releases and authorizations.</strong> You warrant that you have obtained all permissions, waivers, and clearances needed for the creation and use of the work, including owner and occupant permissions where required.</p>
                <p><strong>Indemnification.</strong> You agree to indemnify and hold harmless R/E Pro Photos from claims, costs, or damages related to your breach of these warranties or your negligent or intentional acts or omissions.</p>
                <p><strong>Fees due.</strong> Both you and any party on whose behalf you contract with us remain jointly and severally liable for payment and performance under the agreement.</p>
                <p><strong>Modifications.</strong> R/E Pro Photos may modify these terms at any time. The relationship is governed by the laws of the State of Maryland.</p>
                <p><strong>Identifying information.</strong> We collect identifying information needed to schedule shoots and manage your account, and we safeguard that information appropriately.</p>
                <p><strong>Email permissions.</strong> Email is our primary communication method for account updates and marketing communications. You may opt out of marketing emails by contacting <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a>.</p>
            </div>
        </div>
    </div>
@endsection

@section('footer_note')
    Keep this message as your acceptance record. If you need the latest policy wording, contact our team.
@endsection
