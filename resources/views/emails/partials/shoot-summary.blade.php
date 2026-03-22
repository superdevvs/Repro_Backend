@php
    $showFinancials = $showFinancials ?? true;
    $showNotes = $showNotes ?? true;
    $isPhotographer = $isPhotographer ?? false;
@endphp

<div class="section-card">
    <div class="section-pad">
        <div class="section-kicker">Shoot Overview</div>
        <div class="section-title">{{ $shoot->location }}</div>
        <p class="section-copy">Everything currently scheduled for this property is organized below, including the service lineup and assigned team.</p>

        <div style="margin-top:14px;">
            @if(!empty($shoot->status_label))
                <span class="status-pill{{ in_array(strtolower((string) $shoot->status_label), ['cancelled', 'declined']) ? ' status-danger' : (in_array(strtolower((string) $shoot->status_label), ['on hold', 'pending']) ? ' status-warning' : '') }}">{{ $shoot->status_label }}</span>
            @endif
            @if(!empty($shoot->service_category))
                <span class="pill">{{ $shoot->service_category }}</span>
            @endif
            @if(!empty($shoot->is_private_listing))
                <span class="pill">Exclusive Listing</span>
            @endif
        </div>

        <div class="divider"></div>

        <table class="detail-table" role="presentation">
            <tr>
                <td class="detail-label">Schedule</td>
                <td class="detail-value">
                    {{ $shoot->date }}
                    @if(!empty($shoot->time) && $shoot->time !== 'TBD')
                        <span class="detail-subvalue">Call time: {{ $shoot->time }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="detail-label">Client</td>
                <td class="detail-value">
                    {{ $shoot->client_name ?? 'N/A' }}
                    @if(!empty($shoot->client_email))
                        <span class="detail-subvalue">{{ $shoot->client_email }}</span>
                    @endif
                </td>
            </tr>
            @if(!empty($shoot->rep_name))
                <tr>
                    <td class="detail-label">Sales Rep</td>
                    <td class="detail-value">{{ $shoot->rep_name }}</td>
                </tr>
            @endif
            <tr>
                <td class="detail-label">{{ $isPhotographer ? 'Assigned Team' : 'Photographers' }}</td>
                <td class="detail-value">
                    {{ $shoot->photographers_label ?: 'TBD' }}
                    @if(!empty($shoot->primary_photographer) && count($shoot->photographers ?? []) > 1)
                        <span class="detail-subvalue">Primary lead: {{ $shoot->primary_photographer }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>

@if(!empty($shoot->property_highlights))
    <table class="stats-row" role="presentation">
        <tr>
            @foreach($shoot->property_highlights as $highlight)
                <td>
                    <div class="stat-card">
                        <div class="stat-label">{{ $highlight['label'] }}</div>
                        <div class="stat-value">{{ $highlight['value'] }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
@endif

@if(!empty($shoot->services))
    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Services</div>
            <div class="section-title">Booked deliverables</div>
            <p class="section-copy">Each service line shows quantity, pricing, and the assigned photographer whenever one has been selected.</p>
            <div class="divider"></div>
            <table class="line-table" role="presentation">
                <tr>
                    <th>Service</th>
                    <th style="text-align:right;">Total</th>
                </tr>
                @foreach($shoot->services as $service)
                    <tr>
                        <td>
                            <span class="line-name">{{ $service['display_name'] }}</span>
                            @if(!empty($service['meta']))
                                <span class="line-meta">{{ $service['meta'] }}</span>
                            @endif
                            @if(!empty($service['photographer_name']))
                                <span class="line-meta">Assigned photographer: {{ $service['photographer_name'] }}</span>
                            @endif
                        </td>
                        <td class="amount-cell">{{ $service['formatted_total'] }}</td>
                    </tr>
                @endforeach
                @if($showFinancials)
                    <tr>
                        <td>
                            <span class="line-name">Subtotal</span>
                        </td>
                        <td class="amount-cell">{{ $shoot->formatted_subtotal }}</td>
                    </tr>
                    @if(($shoot->tax ?? 0) > 0)
                        <tr>
                            <td>
                                <span class="line-name">Tax</span>
                                @if(($shoot->tax_rate ?? 0) > 0)
                                    <span class="line-meta">{{ number_format((float) $shoot->tax_rate, 2) }}%</span>
                                @endif
                            </td>
                            <td class="amount-cell">{{ $shoot->formatted_tax }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td>
                            <span class="line-name">Total</span>
                        </td>
                        <td class="amount-cell">{{ $shoot->formatted_grand_total }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>
@endif

@if(!empty($shoot->access_details))
    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Access</div>
            <div class="section-title">Property access details</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                @foreach($shoot->access_details as $detail)
                    <tr>
                        <td class="detail-label">{{ $detail['label'] }}</td>
                        <td class="detail-value">{{ $detail['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

@if($showNotes && (!empty($shoot->notes_lines) || ($isPhotographer && (!empty($shoot->company_notes_lines) || !empty($shoot->photographer_notes_lines)))))
    @if(!empty($shoot->notes_lines))
        <div class="note-card">
            <div class="note-title">Client-facing notes</div>
            <ul class="bullet-list">
                @foreach($shoot->notes_lines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($isPhotographer && !empty($shoot->company_notes_lines))
        <div class="note-card">
            <div class="note-title">Company notes</div>
            <ul class="bullet-list">
                @foreach($shoot->company_notes_lines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($isPhotographer && !empty($shoot->photographer_notes_lines))
        <div class="note-card">
            <div class="note-title">Photographer notes</div>
            <ul class="bullet-list">
                @foreach($shoot->photographer_notes_lines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
