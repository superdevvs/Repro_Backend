<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Producer replay (P2-06), read-only: the Dashboard half of the operator
 * ledger. Give it a database (a SQLite copy, or a named read-only connection)
 * and the website's unconfirmed references. For each one it reports what the
 * Dashboard already holds, so an operator replays only through the same
 * reference and never twice. It also lists website bookings that carry no
 * reference ("shoot-<id>"), which bypass de-duplication and need a person.
 * Exit 0 when every reference is already confirmed or delivered, 1 when
 * something needs a decision, 2 on bad input.
 */
class CrmWebsiteLedger extends Command
{
    private const SOURCE = 'producer_replay_ledger_source';

    private const SETTLED = ['in_dashboard', 'delivered'];

    protected $signature = 'crm:website-ledger
        {--sqlite= : Path to a SQLite copy of the Dashboard database (opened read-only)}
        {--connection= : Or a configured connection with a read-only account}
        {--references= : JSON file {"rows":[{"producer":"booking|contact","reference":"..."}]} from the website export}
        {--since= : Only list reference-less website bookings created after this time (ISO 8601)}
        {--show-references : Include the raw references; by default only their SHA-256 is printed}
        {--json : Print the report as JSON}';

    protected $description = 'Read-only: report the Dashboard state of website bookings and contact inquiries before any replay.';

    public function handle(): int
    {
        $references = $this->references();
        if ($references === null || (! $this->option('sqlite') && ! $this->option('connection'))) {
            $this->error('Give --references and the database to read: --sqlite=<copy> or --connection=<read-only connection>.');

            return 2;
        }
        $db = $this->source();
        if (! $db) {
            return 2;
        }
        try {
            $rows = [];
            foreach ($references as [$producer, $reference]) {
                $rows[] = $producer === 'booking' ? $this->booking($db, $reference) : $this->contact($db, $reference);
            }
            $manual = $this->withoutReference($db);
        } finally {
            $this->release($db);
        }
        $counts = array_count_values(array_column($rows, 'disposition'));
        ksort($counts);
        $report = ['counts' => $counts, 'rows' => $rows, 'manual_reconciliation' => $manual];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line(str_pad($row['producer'], 9).($row['reference'] ?? substr($row['reference_sha256'], 0, 16)).'  '.$row['disposition']);
            }
            $this->line(count($manual).' website booking(s) without a reference need manual reconciliation.');
        }

        return array_diff(array_keys($counts), self::SETTLED) === [] && $manual === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{0: string, 1: string}>|null */
    private function references(): ?array
    {
        $path = (string) $this->option('references');
        $data = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data['rows'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($data['rows'] as $row) {
            $producer = $row['producer'] ?? null;
            $reference = strtolower(trim((string) ($row['reference'] ?? '')));
            if (! in_array($producer, ['booking', 'contact'], true) || $reference === '' || strlen($reference) > 120) {
                return null;
            }
            $out[] = [$producer, $reference];
        }

        return $out;
    }

    private function source(): ?ConnectionInterface
    {
        if ($path = $this->option('sqlite')) {
            $real = realpath((string) $path);
            if ($real === false || ! is_file($real)) {
                $this->error('The SQLite copy does not exist.');

                return null;
            }
            config(['database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => $real, 'prefix' => '',
                'foreign_key_constraints' => false]]);
            $db = DB::connection(self::SOURCE);
            $db->statement('PRAGMA query_only = ON');

            return $db;
        }
        $db = DB::connection((string) $this->option('connection'));
        $db->statement($db->getDriverName() === 'sqlite' ? 'PRAGMA query_only = ON' : 'SET SESSION TRANSACTION READ ONLY');

        return $db;
    }

    private function release(ConnectionInterface $db): void
    {
        if ($db->getName() === self::SOURCE) {
            DB::purge(self::SOURCE);

            return;
        }
        $db->statement($db->getDriverName() === 'sqlite' ? 'PRAGMA query_only = OFF' : 'SET SESSION TRANSACTION READ WRITE');
    }

    private function identity(string $producer, string $reference): array
    {
        return ['producer' => $producer, 'reference_sha256' => hash('sha256', $reference)]
            + ($this->option('show-references') ? ['reference' => $reference] : []);
    }

    private function booking(ConnectionInterface $db, string $reference): array
    {
        $submission = $db->table('external_booking_submissions')->whereRaw('lower(external_reference) = ?', [$reference])->first();
        $shootId = $submission?->shoot_id
            ?? $db->table('shoots')->where('external_booking_payload->external_reference', $reference)->value('id');
        $disposition = match (true) {
            $shootId !== null => 'in_dashboard',
            $submission !== null => 'submission_without_shoot',
            default => 'not_in_dashboard',
        };

        return $this->identity('booking', $reference) + ['disposition' => $disposition, 'shoot_id' => $shootId !== null ? (int) $shootId : null,
            'email_status' => $this->emailStatus($db, 'booking', $reference), 'crm_receipt' => $this->receipt($db, 'booking', $reference)];
    }

    private function contact(ConnectionInterface $db, string $reference): array
    {
        $status = $this->emailStatus($db, 'contact', $reference);
        $disposition = match ($status) {
            'delivered' => 'delivered',
            'sending', 'uncertain' => 'uncertain',
            null => 'not_received',
            default => 'not_delivered',
        };

        return $this->identity('contact', $reference) + ['disposition' => $disposition, 'email_status' => $status,
            'crm_receipt' => $this->receipt($db, 'inquiry', $reference)];
    }

    private function emailStatus(ConnectionInterface $db, string $type, string $reference): ?string
    {
        return $db->table('website_email_deliveries')->where('type', $type)->where('reference', $reference)->value('status');
    }

    private function receipt(ConnectionInterface $db, string $kind, string $reference): string
    {
        $receipt = $db->table('crm_website_intake_receipts')->where('kind', $kind)->where('reference', $reference)->first(['completed_at']);

        return $receipt === null ? 'missing' : ($receipt->completed_at === null ? 'pending' : 'completed');
    }

    /** Website bookings saved without a reference: their CRM receipt is keyed "shoot-<id>". */
    private function withoutReference(ConnectionInterface $db): array
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'))->setTimezone(config('app.timezone')) : null;
        $fromReceipts = $db->table('crm_website_intake_receipts')->where('kind', 'booking')->where('reference', 'like', 'shoot-%')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))->pluck('reference')
            ->map(fn ($reference) => (int) substr((string) $reference, 6));
        $fromShoots = $db->table('shoots')->where('external_booking_payload->source', 'reprophotos.com')
            ->whereNull('external_booking_payload->external_reference')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))->pluck('id')->map(fn ($id) => (int) $id);

        return $fromReceipts->merge($fromShoots)->unique()->sort()->values()
            ->map(fn (int $id) => ['shoot_id' => $id, 'crm_reference' => 'shoot-'.$id])->all();
    }
}
