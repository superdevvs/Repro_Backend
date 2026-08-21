@php
    $isPhotographer = $isPhotographer ?? false;
    $showFinancials = $showFinancials ?? !$isPhotographer;
    $showNotes = $showNotes ?? true;
    $summaryNotice = $summaryNotice ?? null;
    $recipientType = strtolower((string) (($meta->recipient_type ?? null) ?? ($isPhotographer ? 'photographer' : '')));
    $isClientRecipient = $recipientType === 'client';
    $servicesHaveAssignedPhotographers = collect($shoot->services ?? [])->contains(fn ($service) => !empty($service['photographer_name'] ?? null));
@endphp

{{-- Shoot Overview Section Card --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
    <tr>
        <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
            @if(is_array($summaryNotice) && !empty($summaryNotice['value']))
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
                <tr>
                    <td class="callout-danger-bg" style="padding:16px 18px; border-radius:14px; border:1px solid #ffc8cf; background-color:#fff0f1;">
                        <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#a63a4a; font-weight:800;">{{ $summaryNotice['label'] ?? 'Notice' }}</p>
                        <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">{{ $summaryNotice['value'] }}</p>
                    </td>
                </tr>
            </table>
            @endif

            <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Shoot Overview</p>
            <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ $shoot->location }}</p>
            <p class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;">Everything currently scheduled for this property is organized below, including the service lineup and assigned team.</p>

            {{-- Category pills (status pill removed to drop the confusing status bar) --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                <tr>
                    @if(!empty($shoot->service_category))
                        <td style="padding-right:8px; padding-bottom:8px;">
                            <span class="pill-bg" style="display:inline-block; padding:6px 12px; border-radius:999px; font-size:12px; line-height:1.2; font-weight:700; background-color:#edf4ff; border:1px solid #d6e5ff; color:#295391;">{{ $shoot->service_category }}</span>
                        </td>
                    @endif
                    @if(!empty($shoot->is_private_listing))
                        <td style="padding-bottom:8px;">
                            <span class="pill-bg" style="display:inline-block; padding:6px 12px; border-radius:999px; font-size:12px; line-height:1.2; font-weight:700; background-color:#edf4ff; border:1px solid #d6e5ff; color:#295391;">Exclusive Listing</span>
                        </td>
                    @endif
                </tr>
            </table>

            {{-- Divider --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0;">
                <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
            </table>

            {{-- Detail rows --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Schedule</td>
                    <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">
                        {{ $shoot->date }}
                        @if(!empty($shoot->time) && $shoot->time !== 'TBD')
                            <span class="dark-muted" style="display:block; margin-top:3px; color:#7086a3; font-weight:400; font-size:12px;">Call time: {{ $shoot->time }}</span>
                        @endif
                    </td>
                </tr>
                @unless($isClientRecipient)
                <tr>
                    <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Client</td>
                    <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">
                        {{ $shoot->client_name ?? 'N/A' }}
                        @if(!empty($shoot->client_email))
                            <span class="dark-muted" style="display:block; margin-top:3px; color:#7086a3; font-weight:400; font-size:12px;">{{ $shoot->client_email }}</span>
                        @endif
                    </td>
                </tr>
                @if(!empty($shoot->rep_name))
                <tr>
                    <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Sales Rep</td>
                    <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $shoot->rep_name }}</td>
                </tr>
                @endif
                @endunless
                @if(!$servicesHaveAssignedPhotographers)
                <tr>
                    <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">{{ $isPhotographer ? 'Assigned Team' : 'Photographers' }}</td>
                    <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">
                        {{ $shoot->photographers_label ?: 'TBD' }}
                        @if(!empty($shoot->primary_photographer) && count($shoot->photographers ?? []) > 1)
                            <span class="dark-muted" style="display:block; margin-top:3px; color:#7086a3; font-weight:400; font-size:12px;">Primary lead: {{ $shoot->primary_photographer }}</span>
                        @endif
                    </td>
                </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- Property highlights --}}
@if(!empty($shoot->property_highlights))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
    <tr>
        @foreach($shoot->property_highlights as $highlight)
            <td class="stat-td stat-card-bg" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                            <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">{{ $highlight['label'] }}</p>
                            <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ $highlight['value'] }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        @endforeach
    </tr>
</table>
@endif

{{-- Services --}}
@if(!empty($shoot->services))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
    <tr>
        <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
            <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Services</p>
            <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Booked deliverables</p>
            <p class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;">Each service line shows quantity, pricing, and the assigned photographer whenever one has been selected.</p>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 0;">
                <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
            </table>

            {{-- Service line items --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top; font-size:11px; line-height:1.4; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Service</td>
                    @if(!$isPhotographer)
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; vertical-align:top; font-size:11px; line-height:1.4; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Total</td>
                    @endif
                </tr>
                @foreach($shoot->services as $service)
                <tr>
                    <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top;">
                        <span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">{{ $service['display_name'] }}</span>
                        @if(!empty($service['meta']))
                            <span class="dark-muted" style="display:block; margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">{{ $service['meta'] }}</span>
                        @endif
                        @if(!empty($service['photographer_name']))
                            <span class="dark-muted" style="display:block; margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">Assigned photographer: {{ $service['photographer_name'] }}</span>
                        @endif
                    </td>
                    @if(!$isPhotographer)
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; vertical-align:top; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ $service['formatted_total'] }}</td>
                    @endif
                </tr>
                @endforeach
                @if($showFinancials)
                <tr>
                    <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top;">
                        <span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">Subtotal</span>
                    </td>
                    <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; vertical-align:top; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ $shoot->formatted_subtotal }}</td>
                </tr>
                @if(($shoot->tax ?? 0) > 0)
                <tr>
                    <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top;">
                        <span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">Tax</span>
                        @if(($shoot->tax_rate ?? 0) > 0)
                            <span class="dark-muted" style="display:block; margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">{{ number_format((float) $shoot->tax_rate, 2) }}%</span>
                        @endif
                    </td>
                    <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; vertical-align:top; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ $shoot->formatted_tax }}</td>
                </tr>
                @endif
                <tr>
                    <td class="line-td" style="padding:12px 0; text-align:left; vertical-align:top;">
                        <span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">Total</span>
                    </td>
                    <td class="line-td amount-td dark-heading" style="padding:12px 0; text-align:right; vertical-align:top; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ $shoot->formatted_grand_total }}</td>
                </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
@endif

{{-- Access details --}}
@if(!empty($shoot->access_details))
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
    <tr>
        <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
            <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Access</p>
            <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Property access details</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                @foreach($shoot->access_details as $i => $detail)
                <tr>
                    <td class="detail-label-td dark-muted {{ $loop->last ? '' : 'detail-border' }}" width="34%" style="padding:10px 14px 10px 0; {{ $loop->last ? '' : 'border-bottom:1px solid #edf2f7;' }} vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">{{ $detail['label'] }}</td>
                    <td class="detail-value-td dark-heading {{ $loop->last ? '' : 'detail-border' }}" style="padding:10px 0; {{ $loop->last ? '' : 'border-bottom:1px solid #edf2f7;' }} vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $detail['value'] }}</td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>
@endif

{{-- Notes --}}
@if($showNotes && (!empty($shoot->notes_lines) || ($isPhotographer && (!empty($shoot->company_notes_lines) || !empty($shoot->photographer_notes_lines)))))
    @if(!empty($shoot->notes_lines))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
        <tr>
            <td class="note-card-bg" style="border-radius:14px; background-color:#f8fbff; border:1px solid #dbe7f8; padding:18px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:13px; line-height:1.5; letter-spacing:1.3px; text-transform:uppercase; color:#60799a; font-weight:800;">Notes</p>
                <ul class="dark-body" style="margin:0; padding-left:18px; color:#35506f; font-size:14px; line-height:1.7;">
                    @foreach($shoot->notes_lines as $line)
                        <li style="margin-bottom:8px;">{{ $line }}</li>
                    @endforeach
                </ul>
            </td>
        </tr>
    </table>
    @endif

    @if($isPhotographer && !empty($shoot->company_notes_lines))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
        <tr>
            <td class="note-card-bg" style="border-radius:14px; background-color:#f8fbff; border:1px solid #dbe7f8; padding:18px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:13px; line-height:1.5; letter-spacing:1.3px; text-transform:uppercase; color:#60799a; font-weight:800;">Company notes</p>
                <ul class="dark-body" style="margin:0; padding-left:18px; color:#35506f; font-size:14px; line-height:1.7;">
                    @foreach($shoot->company_notes_lines as $line)
                        <li style="margin-bottom:8px;">{{ $line }}</li>
                    @endforeach
                </ul>
            </td>
        </tr>
    </table>
    @endif

    @if($isPhotographer && !empty($shoot->photographer_notes_lines))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
        <tr>
            <td class="note-card-bg" style="border-radius:14px; background-color:#f8fbff; border:1px solid #dbe7f8; padding:18px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:13px; line-height:1.5; letter-spacing:1.3px; text-transform:uppercase; color:#60799a; font-weight:800;">Photographer notes</p>
                <ul class="dark-body" style="margin:0; padding-left:18px; color:#35506f; font-size:14px; line-height:1.7;">
                    @foreach($shoot->photographer_notes_lines as $line)
                        <li style="margin-bottom:8px;">{{ $line }}</li>
                    @endforeach
                </ul>
            </td>
        </tr>
    </table>
    @endif
@endif
