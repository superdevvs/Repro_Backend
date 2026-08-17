@php
    $buildRemovedListHtml = function (string $label, string $before, string $after): string {
        $before = trim($before);
        $after = trim($after);

        if ($before === '') {
            return e('Not set');
        }

        if (strcasecmp($after, 'Removed') === 0) {
            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($before) . '</span>';
        }

        if (strcasecmp($label, 'Services') !== 0) {
            return e($before);
        }

        $beforeItems = collect(preg_split('/\s*,\s*/', $before))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->values();

        $afterItems = collect(preg_split('/\s*,\s*/', $after))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->countBy(fn ($item) => mb_strtolower($item));

        if ($beforeItems->isEmpty()) {
            return e($before);
        }

        return $beforeItems->map(function ($item) use ($afterItems) {
            $key = mb_strtolower($item);
            $remaining = (int) ($afterItems->get($key, 0));

            if ($remaining > 0) {
                $afterItems->put($key, $remaining - 1);

                return e($item);
            }

            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($item) . '</span>';
        })->implode(', ');
    };

    $changeLines = collect(preg_split('/\r\n|\r|\n/', trim((string) ($changesSummary ?? ''))))
        ->filter(fn ($line) => trim((string) $line) !== '')
        ->map(function ($line) use ($buildRemovedListHtml) {
            $line = trim((string) $line);

            if (!str_contains($line, ':')) {
                return [
                    'type' => 'text',
                    'text' => $line,
                ];
            }

            [$label, $value] = explode(':', $line, 2);
            $label = trim($label);
            $value = trim($value);

            if (preg_match('/^removed\s+\(was\s+(.+)\)$/i', $value, $matches)) {
                return [
                    'type' => 'comparison',
                    'label' => $label,
                    'before' => trim((string) ($matches[1] ?? '')),
                    'before_html' => $buildRemovedListHtml($label, trim((string) ($matches[1] ?? '')), 'Removed'),
                    'after' => 'Removed',
                ];
            }

            if (preg_match('/\s(?:→|->)\s/u', $value) === 1) {
                [$before, $after] = preg_split('/\s*(?:→|->)\s*/u', $value, 2);

                return [
                    'type' => 'comparison',
                    'label' => $label,
                    'before' => trim((string) $before),
                    'before_html' => $buildRemovedListHtml($label, trim((string) $before), trim((string) $after)),
                    'after' => trim((string) $after),
                ];
            }

            return [
                'type' => 'single',
                'label' => $label,
                'value' => $value,
            ];
        })
        ->values();

    $comparisonChanges = $changeLines->filter(fn ($change) => ($change['type'] ?? null) === 'comparison')->values();
    $singleChanges = $changeLines->filter(fn ($change) => ($change['type'] ?? null) === 'single')->values();
    $textChanges = $changeLines->filter(fn ($change) => ($change['type'] ?? null) === 'text')->values();
@endphp

@if($changeLines->isNotEmpty())
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
    <tr>
        <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
            <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Updated Details</p>
            <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">What changed</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0;">
                <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
            </table>
            @if($comparisonChanges->isNotEmpty() || $singleChanges->isNotEmpty())
                @foreach($comparisonChanges as $change)
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 12px;">
                        <tr>
                            <td class="note-card-bg" style="border:1px solid #dbe6f3; border-radius:14px; padding:16px 18px; background-color:#f8fbff;">
                                <p class="dark-heading" style="margin:0 0 12px; font-size:14px; line-height:1.5; color:#10233b; font-weight:800;">{{ $change['label'] }}</p>
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                    <tr>
                                        <td class="footer-meta-td" width="50%" style="padding-right:8px; vertical-align:top;">
                                            <p class="dark-muted" style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Before</p>
                                            <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#2d4769;">{!! $change['before_html'] ?? e($change['before'] !== '' ? $change['before'] : 'Not set') !!}</p>
                                        </td>
                                        <td class="footer-meta-td" width="50%" style="padding-left:8px; vertical-align:top;">
                                            <p class="dark-muted" style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">After</p>
                                            <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{{ $change['after'] !== '' ? $change['after'] : 'Not set' }}</p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                @endforeach
                @foreach($singleChanges as $change)
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 12px;">
                        <tr>
                            <td class="note-card-bg" style="border:1px solid #dbe6f3; border-radius:14px; padding:16px 18px; background-color:#f8fbff;">
                                <p class="dark-heading" style="margin:0 0 4px; font-size:14px; line-height:1.5; color:#10233b; font-weight:800;">{{ $change['label'] }}</p>
                                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{{ $change['value'] !== '' ? $change['value'] : 'Not set' }}</p>
                            </td>
                        </tr>
                    </table>
                @endforeach
            @endif

            @foreach($textChanges as $change)
                <p class="dark-body" style="margin:0 0 12px; font-size:14px; line-height:1.7; color:#2d4769;">{{ $change['text'] ?? '' }}</p>
            @endforeach
        </td>
    </tr>
</table>
@endif
