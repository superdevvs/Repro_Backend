@component('mail::message')
# New special editing request

Tracking code: **{{ $request->tracking_code }}**

@if($request->shoot_id)
- Shoot ID: {{ $request->shoot_id }}
@endif
- Requested by: {{ optional($request->requester)->name ?? 'Unknown' }}
- Priority: {{ ucfirst($request->priority) }}
- Status: {{ ucfirst($request->status) }}
- Target team: {{ ucfirst($request->target_team) }}

**Summary**  
{{ $request->summary }}

@if($request->details)
**Details**  
{{ $request->details }}
@endif

Please update the request in the dashboard once you pick it up so editors, reps, and admins stay aligned.

Thanks!  
— Workflow Bot
@endcomponent

