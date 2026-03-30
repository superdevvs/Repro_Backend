@php
    $changeLines = collect(preg_split('/\r\n|\r|\n/', trim((string) ($changesSummary ?? ''))))
        ->filter(fn ($line) => trim((string) $line) !== '')
        ->values();
@endphp

@if($changeLines->isNotEmpty())
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
    <tr>
        <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
            <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Changed Details</p>
            <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">What changed</p>
            <p class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;">Each updated field is listed below so the newest version of this shoot is clear at a glance.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0;">
                <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
            </table>
            <ul class="dark-heading" style="margin:0; padding-left:18px; color:#10233b; font-size:14px; line-height:1.65;">
                @foreach($changeLines as $line)
                    <li style="margin-bottom:10px;">{{ $line }}</li>
                @endforeach
            </ul>
        </td>
    </tr>
</table>
@endif
