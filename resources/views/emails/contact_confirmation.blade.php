@extends('emails.layouts.master')

@section('title', 'Thank You for Contacting Us')
@section('preheader', 'We received your message and will follow up soon.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Message Received</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Thanks for reaching out.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">Your message has been delivered and a team member will follow up as soon as possible.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">We have your message on file.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Your Message</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ $client->name ?? 'R/E Pro Photos' }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:16px;">
                    <tr>
                        <td class="note-card-bg" style="border-radius:14px; background-color:#f8fbff; border:1px solid #dbe7f8; padding:18px;">
                            <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#4f6886;">{!! nl2br(e($submission->message)) !!}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">What to expect next</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Our team will review your message and respond using the contact information you provided. There is no need to send it again unless you want to add more details.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    This is an automated confirmation message.
@endsection
