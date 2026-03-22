@php
    $changeLines = collect(preg_split('/\r\n|\r|\n/', trim((string) ($changesSummary ?? ''))))
        ->filter(fn ($line) => trim((string) $line) !== '')
        ->values();
@endphp

@if($changeLines->isNotEmpty())
    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Changed Details</div>
            <div class="section-title">What changed</div>
            <p class="section-copy">Each updated field is listed below so the newest version of this shoot is clear at a glance.</p>
            <div class="divider"></div>
            <ul class="change-list">
                @foreach($changeLines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
