@extends('emails.layouts.master')

@section('title', !empty($isAdmin) ? 'New Shoot Request Needs Review' : 'We Received Your Shoot Request')
@section('preheader', !empty($isAdmin) ? 'A new requested shoot is waiting for review in the dashboard.' : 'Your shoot request is in review. We included the submitted details below for your records.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Request Received</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:32px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">
        {{ !empty($isAdmin) ? 'A new shoot request needs review.' : 'Your shoot request is in review.' }}
    </p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">
        {{ !empty($isAdmin)
            ? 'The request details below are ready for the office team to review, approve, or update from the dashboard.'
            : 'Our team has the request and will review the schedule, service details, and any follow-up needs shortly.' }}
    </p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;">
        <strong class="dark-strong" style="color:#071223;">
            {{ !empty($isAdmin) ? 'Please review this request in the dashboard.' : 'No further action is needed right now.' }}
        </strong>
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', [
        'shoot' => $shoot,
    ])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">
                    {{ !empty($isAdmin) ? 'Office follow-up' : 'What happens next?' }}
                </p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">
                    {{ !empty($isAdmin)
                        ? 'Confirm the request details, assign the right team, and update the requester if anything needs clarification before approval.'
                        : 'We will send a follow-up once the office reviews the request. If we need anything else, we will reach out using the contact details on file.' }}
                </p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    {{ !empty($isAdmin)
        ? 'Keep this email until the requested shoot is reviewed and resolved.'
        : 'Keep this email for your records while the request is under review.' }}
@endsection
