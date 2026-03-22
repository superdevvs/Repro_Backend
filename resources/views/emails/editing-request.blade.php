@extends('emails.layouts.master')

@section('title', 'New Special Editing Request')
@section('preheader', 'A special editing request is waiting for review.')

@section('hero')
    <div class="eyebrow">Editing Workflow</div>
    <h1 class="hero-title">A new editing request needs attention.</h1>
    <p class="hero-copy">This request has been logged into the workflow and includes the target team, priority, and context needed to pick it up quickly.</p>
@endsection

@section('content')
    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Request Summary</div>
            <div class="section-title">{{ $request->tracking_code }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                @if($request->shoot_id)
                    <tr>
                        <td class="detail-label">Shoot ID</td>
                        <td class="detail-value">{{ $request->shoot_id }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="detail-label">Requested by</td>
                    <td class="detail-value">{{ optional($request->requester)->name ?? 'Unknown' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Priority</td>
                    <td class="detail-value">{{ ucfirst($request->priority) }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Status</td>
                    <td class="detail-value">{{ ucfirst($request->status) }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Target team</td>
                    <td class="detail-value">{{ ucfirst($request->target_team) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="note-card">
        <div class="note-title">Summary</div>
        <div class="body-copy" style="margin-top:0;">{{ $request->summary }}</div>
    </div>

    @if($request->details)
        <div class="note-card">
            <div class="note-title">Details</div>
            <div class="body-copy" style="margin-top:0;">{{ $request->details }}</div>
        </div>
    @endif

    <div class="callout">
        <div class="callout-title">Keep the team aligned</div>
        <p class="callout-copy">Please update the request in the dashboard as soon as someone picks it up so editors, reps, and admins stay on the same page.</p>
    </div>
@endsection
