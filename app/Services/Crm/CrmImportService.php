<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * CSV import in two steps: preview (nothing saved) and commit.
 *
 * Every row is checked against every contact in the system and against the
 * other rows in the file. Email and phone duplicates are skipped; same-name
 * rows are skipped unless an admin marks them as a different person. Sales
 * reps always import into their own name; only admins choose the owner.
 */
class CrmImportService
{
    public const FIELDS = ['name', 'company', 'title', 'email', 'phone', 'address', 'website', 'market_state', 'market_city', 'segment', 'lead_source'];

    private const HEADER_GUESSES = [
        'name' => ['name', 'full name', 'contact', 'contact name', 'agent', 'agent name'],
        'company' => ['company', 'brokerage', 'office', 'organization', 'company / brokerage'],
        'title' => ['title', 'job title', 'position', 'role'],
        'email' => ['email', 'e-mail', 'email address'],
        'phone' => ['phone', 'mobile', 'cell', 'phone number', 'telephone'],
        'address' => ['address', 'mailing address', 'street'],
        'website' => ['website', 'url', 'site', 'web'],
        'market_state' => ['state', 'market state', 'st'],
        'market_city' => ['city', 'metro', 'market', 'city or metro'],
        'segment' => ['segment', 'type'],
        'lead_source' => ['source', 'lead source'],
    ];

    public function __construct(
        private readonly CrmDuplicateGuard $duplicates,
        private readonly CrmContactService $contacts,
        private readonly CrmAccess $access,
    ) {}

    /** @return array{headers: string[], rows: array<int, array<int, string>>} */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $headers = null;
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $line = array_map(fn ($v) => trim((string) $v), $line);
            if ($headers === null) {
                $headers = $line;

                continue;
            }
            $rows[] = $line;
            if (count($rows) > 5000) {
                throw ValidationException::withMessages(['file' => 'Import up to 5,000 rows at a time.']);
            }
        }
        fclose($handle);

        if (! $headers) {
            throw ValidationException::withMessages(['file' => 'The file has no header row.']);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @param  string[]  $headers  @return array<string, int|null> field => column index */
    public function guessMapping(array $headers): array
    {
        $lower = array_map(fn ($h) => strtolower(trim($h)), $headers);
        $mapping = [];
        foreach (self::HEADER_GUESSES as $field => $guesses) {
            $mapping[$field] = null;
            foreach ($guesses as $guess) {
                $index = array_search($guess, $lower, true);
                if ($index !== false && ! in_array($index, $mapping, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, int|null>  $mapping
     * @param  array{default_segment?: string, lead_source?: string, allow_name_rows?: int[]}  $options
     */
    public function preview(array $parsed, array $mapping, array $options = []): array
    {
        $seen = ['email' => [], 'phone' => []];
        $allowName = array_flip(array_map('intval', $options['allow_name_rows'] ?? []));
        $results = [];

        foreach ($parsed['rows'] as $i => $cells) {
            $rowNumber = $i + 2; // header is row 1
            $data = $this->rowData($cells, $mapping, $options);
            $result = ['row' => $rowNumber, 'data' => $data, 'status' => 'ready', 'reason' => null, 'match' => null];

            if (($data['name'] ?? '') === '') {
                $result['status'] = 'invalid';
                $result['reason'] = 'Missing name';
            } elseif (! in_array($data['segment'] ?? null, CrmContact::SEGMENTS, true)) {
                $result['status'] = 'invalid';
                $result['reason'] = 'Missing or unknown segment';
            } elseif (($data['email'] ?? null) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $result['status'] = 'invalid';
                $result['reason'] = 'Email address is not valid';
            } else {
                $email = CrmNormalizer::email($data['email'] ?? null);
                $phone = CrmNormalizer::phone($data['phone'] ?? null);
                if ($email && isset($seen['email'][$email])) {
                    $result['status'] = 'duplicate';
                    $result['reason'] = 'Appears twice in this file (row '.$seen['email'][$email].')';
                } elseif ($phone && isset($seen['phone'][$phone])) {
                    $result['status'] = 'duplicate';
                    $result['reason'] = 'Appears twice in this file (row '.$seen['phone'][$phone].')';
                } elseif ($match = $this->duplicates->find($data)) {
                    if ($match->type === 'name' && isset($allowName[$rowNumber])) {
                        $result['status'] = 'ready';
                    } else {
                        $result['status'] = $match->type === 'name' ? 'name_match' : 'duplicate';
                        $result['reason'] = match ($match->type) {
                            'email' => 'Same email as an existing contact',
                            'phone' => 'Same phone as an existing contact',
                            default => 'Same full name as an existing contact',
                        };
                        $result['match'] = ['type' => $match->type, 'contact_id' => $match->contact->id];
                    }
                }
                if ($email) {
                    $seen['email'][$email] ??= $rowNumber;
                }
                if ($phone) {
                    $seen['phone'][$phone] ??= $rowNumber;
                }
            }
            $results[] = $result;
        }

        $counts = ['total' => count($results), 'ready' => 0, 'duplicate' => 0, 'name_match' => 0, 'invalid' => 0];
        foreach ($results as $r) {
            $counts[$r['status']]++;
        }

        return ['counts' => $counts, 'rows' => $results];
    }

    /**
     * Saves the rows that are ready. Returns counts; skipped rows are not saved.
     *
     * @param  array<string, int|null>  $mapping
     */
    public function commit(array $parsed, array $mapping, User $actor, ?int $assignTo, array $options = []): array
    {
        $isAdmin = $this->access->canAssign($actor);
        $owner = $isAdmin ? $assignTo : $actor->id;

        $preview = $this->preview($parsed, $mapping, $options);
        $created = 0;
        $skipped = 0;
        $allowName = array_flip(array_map('intval', $options['allow_name_rows'] ?? []));

        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== 'ready') {
                $skipped++;

                continue;
            }
            try {
                $this->contacts->create($row['data'], $actor, [
                    'source' => 'import',
                    'origin' => 'import',
                    'assigned_rep_id' => $owner,
                    'confirm_different_person' => $isAdmin && isset($allowName[$row['row']]),
                ]);
                $created++;
            } catch (CrmDuplicateException) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'counts' => $preview['counts']];
    }

    private function rowData(array $cells, array $mapping, array $options): array
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            $index = $mapping[$field] ?? null;
            $value = $index !== null && isset($cells[$index]) ? trim((string) $cells[$index]) : '';
            if ($value !== '') {
                $data[$field] = mb_substr($value, 0, 190);
            }
        }

        $segment = strtolower($data['segment'] ?? '');
        $data['segment'] = match (true) {
            str_starts_with($segment, 'res') => 'residential',
            str_starts_with($segment, 'com') => 'commercial',
            default => $options['default_segment'] ?? null,
        };

        $source = strtolower(str_replace([' ', '-'], '_', $data['lead_source'] ?? ''));
        $data['lead_source'] = in_array($source, CrmContact::LEAD_SOURCES, true) ? $source : ($options['lead_source'] ?? 'other');

        return array_filter($data, fn ($v) => $v !== null);
    }
}
