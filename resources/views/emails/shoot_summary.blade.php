@extends('emails.layouts.master')

@section('title', 'Shoot Summary')
@section('preheader', 'Your completed shoot and available delivery links are ready.')

@php
    $showWebsiteTile = false;
    $showReviewTile = false;
    $summaryLinks = collect(is_array($deliveryShare['links'] ?? null) ? $deliveryShare['links'] : [])->keyBy('key');
    $additionalLinks = is_array($deliveryShare['additional_links'] ?? null) ? $deliveryShare['additional_links'] : [];
    $dashboardUrl = $shoot->dashboard_url ?? config('app.frontend_url', 'https://reprodashboard.com');
    $linkSections = [
        ['heading' => 'Image downloads', 'keys' => ['small_zip', 'full_zip']],
        ['heading' => 'Virtual tours', 'keys' => ['mls_tour', 'branded_tour']],
        ['heading' => 'Property and video', 'keys' => ['property', 'video']],
        ['heading' => 'Zillow 3D', 'keys' => ['zillow_3d']],
    ];
@endphp

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Shoot Summary</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Your shoot is ready to share.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">Your completed shoot and its available downloads, tours, and property links are gathered below.</p>
@endsection

@section('content')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Property</p>
                <p class="dark-heading" style="margin:0; font-size:20px; line-height:1.4; font-weight:800; color:#071223;">{{ $shoot->location ?? 'Your shoot' }}</p>
                @if(!empty($shoot->date) && $shoot->date !== 'TBD')
                    <p class="dark-body" style="margin:8px 0 0; font-size:14px; line-height:1.6; color:#47627f;">Photographed {{ $shoot->date }}</p>
                @endif
                @if(!empty($shoot->services))
                    <p class="dark-muted" style="margin:16px 0 6px; font-size:12px; line-height:1.4; color:#6c84a2; font-weight:700;">Services</p>
                    @foreach($shoot->services as $service)
                        @if(!empty($service['display_name'] ?? null))
                            <p class="dark-body" style="margin:4px 0; font-size:14px; line-height:1.6; color:#47627f;">{{ $service['display_name'] }}</p>
                        @endif
                    @endforeach
                @endif
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 20px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $dashboardUrl }}" style="display:inline-block; padding:16px 26px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:15px; line-height:1.2; text-decoration:none;">Open Shoot in Dashboard</a>
            </td>
        </tr>
    </table>

    @foreach($linkSections as $section)
        @php
            $sectionLinks = collect($section['keys'])->map(fn ($key) => $summaryLinks->get($key))->filter(fn ($link) => is_array($link) && !empty($link['url']));
        @endphp
        @if($sectionLinks->isNotEmpty())
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
                <tr>
                    <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                        <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">{{ $section['heading'] }}</p>
                        @foreach($sectionLinks as $link)
                            <p class="dark-heading" style="margin:12px 0 4px; font-size:14px; line-height:1.5; color:#10233b; font-weight:700;">{{ $link['label'] }}</p>
                            <p class="dark-body" style="margin:0 0 12px; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $link['url'] }}" style="color:#1463ff; text-decoration:underline;">{{ $link['url'] }}</a></p>
                        @endforeach
                    </td>
                </tr>
            </table>
        @endif
    @endforeach

    @if($additionalLinks !== [])
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Additional links</p>
                    @foreach($additionalLinks as $link)
                        @if(!empty($link['url']))
                            <p class="dark-heading" style="margin:12px 0 4px; font-size:14px; line-height:1.5; color:#10233b; font-weight:700;">{{ $link['label'] }}</p>
                            <p class="dark-body" style="margin:0 0 12px; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $link['url'] }}" style="color:#1463ff; text-decoration:underline;">{{ $link['url'] }}</a></p>
                        @endif
                    @endforeach
                </td>
            </tr>
        </table>
    @endif

    @if($summaryLinks->has('small_zip') || $summaryLinks->has('full_zip'))
        <p class="dark-muted" style="margin:0 0 16px; font-size:13px; line-height:1.6; color:#6f86a4;">Direct ZIP links may expire after seven days. You can open the shoot in your dashboard for current access.</p>
    @elseif($summaryLinks->isEmpty())
        <p class="dark-body" style="margin:0 0 16px; font-size:14px; line-height:1.7; color:#47627f;">Your shoot is available in the dashboard. Any downloadable files and share links will appear there when available.</p>
    @endif
@endsection

@section('footer_note')
    Keep this summary for quick access to the property. You can always find the latest files in your dashboard.
@endsection
