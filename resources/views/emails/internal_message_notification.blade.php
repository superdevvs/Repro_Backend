@extends('emails.layouts.master')

@section('title', 'New Dashboard Message')
@section('preheader', 'A new dashboard message is waiting for you.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">New Dashboard Message</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#e8edf5;">A message is waiting for you.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#a9b8cb;">Open the dashboard to read the complete conversation and reply securely.</p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#a9b8cb;"><strong class="dark-strong" style="color:#e8edf5;">Hi {{ $recipient->name ?? 'there' }},</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#16233a; border:1px solid #24344d; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#8298b4; font-weight:700;">Message Details</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">From</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $meta->sender_name ?? 'Dashboard user' }} ({{ $meta->sender_role ?? 'User' }})</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Client / Account</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $account->company_name ?? $account->name ?? 'Client account' }}</td>
                    </tr>
                    @if(!empty($shoot->id))
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Related Shoot</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">
                            {{ $shoot->address ?? ('Shoot #' . $shoot->id) }}@if(!empty($shoot->city) || !empty($shoot->state)), {{ collect([$shoot->city ?? null, $shoot->state ?? null])->filter()->implode(', ') }}@endif
                        </td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="note-card-bg section-inner" style="background-color:#16233a; border:1px solid #24344d; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#8298b4; font-weight:700;">Preview</p>
                <p class="dark-body" style="margin:0; font-size:15px; line-height:1.75; color:#a9b8cb;">{{ $meta->message_preview ?: 'Open the dashboard to view this message.' }}</p>
            </td>
        </tr>
    </table>

    @if(!empty($links['message']))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 22px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $links['message'] }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">View Message</a>
            </td>
        </tr>
    </table>
    @endif
@endsection

@section('footer_note')
    This is only a notification. The full message remains in your secure dashboard conversation.
@endsection
