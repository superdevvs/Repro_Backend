@extends('emails.layouts.master')
@section('title', 'New Special Editing Request')
@section('content')
    <h3>New special editing request</h3>

    <p>Tracking code: <strong>{{ $request->tracking_code }}</strong></p>

    <ul>
        @if($request->shoot_id)
        <li>Shoot ID: {{ $request->shoot_id }}</li>
        @endif
        <li>Requested by: {{ optional($request->requester)->name ?? 'Unknown' }}</li>
        <li>Priority: {{ ucfirst($request->priority) }}</li>
        <li>Status: {{ ucfirst($request->status) }}</li>
        <li>Target team: {{ ucfirst($request->target_team) }}</li>
    </ul>

    <p><strong>Summary</strong><br>{{ $request->summary }}</p>

    @if($request->details)
    <p><strong>Details</strong><br>{{ $request->details }}</p>
    @endif

    <p>Please update the request in the dashboard once you pick it up so editors, reps, and admins stay aligned.</p>

    <p>Thanks!<br>— Workflow Bot</p>
@endsection

