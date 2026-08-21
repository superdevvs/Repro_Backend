@extends('emails.layouts.master')

@section('title', 'Shoot Request Updated')
@section('preheader', 'The requested shoot was approved with the changes listed below.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Request Updated</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Your shoot request was updated.</p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;">Your request has been approved. Only the details changed during approval are listed here.</p>

    @include('emails.partials.change-summary', ['changesSummary' => $changesSummary ?? null])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Review Updated Request</a>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    The dashboard contains the current approved request.
@endsection
