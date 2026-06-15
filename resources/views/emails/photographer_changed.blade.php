@extends('emails.layouts.master')

@php
    $changeHeadline = !empty($isAssignedAfterChange)
        ? 'You have been assigned to this shoot.'
        : 'You are no longer assigned to this shoot.';
    $changeCopy = !empty($isAssignedAfterChange)
        ? 'Please review the latest timing, services, and access details below before the appointment.'
        : 'The office has updated the assignment. The latest shoot details are included below for reference.';
@endphp

@section('title', 'Photographer Assignment Updated')
@section('preheader', 'A photographer assignment changed for one of your shoots.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Photographer Change</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">{{ $changeHeadline }}</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">{{ $changeCopy }}</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Assignment update</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">{{ !empty($isAssignedAfterChange) ? 'Current assignment' : 'Previous assignment' }}</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">
                    @if(!empty($isAssignedAfterChange))
                        You are currently assigned to cover this shoot.
                    @else
                        You have been removed from the current photographer roster for this shoot.
                    @endif
                </p>
                @if(!empty($previousPhotographer?->name))
                    <p class="dark-body" style="margin:12px 0 0; font-size:14px; line-height:1.7; color:#47627f;">Previous lead: {{ $previousPhotographer->name }}</p>
                @endif
            </td>
        </tr>
    </table>

    @include('emails.partials.change-summary', ['changesSummary' => $changesSummary ?? null])
    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => true])
@endsection

@section('footer_note')
    This email only goes to the photographer team members affected by the reassignment.
@endsection
