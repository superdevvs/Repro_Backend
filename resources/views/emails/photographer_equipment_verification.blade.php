@extends('emails.layouts.master')

@section('title', 'Equipment Verification Required')
@section('preheader', 'Review your photographer equipment and complete verification.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">Equipment Verification</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#e8edf5;">Review and verify your equipment.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#a9b8cb;">Open your photographer profile to review assigned equipment. Upload current photos for each item that requires verification so our admin team can approve it.</p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#a9b8cb;"><strong class="dark-strong" style="color:#e8edf5;">Hi {{ $recipient->name ?? 'there' }},</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 22px; table-layout:fixed;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $equipmentVerificationUrl }}" style="display:block; box-sizing:border-box; width:100%; max-width:100%; padding:14px 18px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.25; text-align:center; white-space:normal; overflow-wrap:anywhere; text-decoration:none;">Verify Equipment</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #24344d; background-color:#16233a;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#e8edf5; font-weight:800;">What to upload</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">Open the Equipments tab in your profile. If equipment is already assigned, upload clear verification photos showing the item and serial number where available. If nothing is assigned yet, return after your admin adds equipment.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    If this equipment assignment looks incorrect, contact the admin team before uploading photos.
@endsection
