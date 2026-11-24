<?php

namespace App\Console\Commands;

use App\Models\Crm\CrmContact;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Crm\CrmAssignmentService;
use App\Services\Crm\CrmClientLink;
use App\Services\Crm\CrmClientShootStats;
use App\Services\Crm\CrmContactService;
use App\Support\LockedWrite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings existing Dashboard clients into the CRM once, at launch. Safe to run
 * again: clients that already have a contact are skipped.
 *
 * - Clients with a completed shoot go to the client pool, one-off or recurring
 *   by shoot count. Clients with only upcoming shoots go to Booked 1st shoot.
 * - Segment defaults to Residential and is flagged for review.
 * - No rep on record: the contact goes to the Unassigned queue.
 * - Shared office email or phone: imported and flagged, not blocked.
 */
class CrmImportClients extends Command
{
    protected $signature = 'crm:import-clients {--dry-run : Count what would happen without saving} {--limit=0 : Stop after this many clients}';

    protected $description = 'Import existing Dashboard clients into the Sales CRM client pool (idempotent).';

    public function handle(CrmClientLink $link, CrmContactService $contacts, CrmAssignmentService $assignment): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $counts = ['clients' => 0, 'skipped_existing' => 0, 'linked' => 0, 'created' => 0, 'client_pool' => 0, 'booked' => 0, 'no_shoots' => 0, 'unassigned' => 0, 'invalid_rep' => 0, 'flagged' => 0];

        $query = User::query()->where('role', 'client')->orderBy('id');
        foreach ($query->lazyById(200) as $client) {
            if ($limit > 0 && $counts['clients'] >= $limit) {
                break;
            }
            $counts['clients']++;

            if (CrmContact::unscopedForSystem()->where('client_user_id', $client->id)->exists()) {
                $counts['skipped_existing']++;

                continue;
            }

            $stats = DB::table('shoots')->where('client_id', $client->id)
                ->whereNotIn('status', CrmClientShootStats::IGNORED_STATUSES)
                ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as done, MIN(completed_at) as first_done')
                ->first();
            $done = (int) ($stats->done ?? 0);
            $stage = $done > 0 ? CrmContact::STAGE_CLIENT_POOL : ((int) ($stats->total ?? 0) > 0 ? CrmContact::STAGE_BOOKED : CrmContact::STAGE_OUTREACH);
            $counts[$stage === CrmContact::STAGE_CLIENT_POOL ? 'client_pool' : ($stage === CrmContact::STAGE_BOOKED ? 'booked' : 'no_shoots')]++;

            $repId = CrmClientLink::rawRepIdFor($client);
            $invalidRep = $repId && ! $assignment->isAssignable($repId);
            if ($invalidRep) {
                $repId = null;
                $counts['invalid_rep']++;
            }
            if (! $repId) {
                $counts['unassigned']++;
            }

            if ($dry) {
                continue;
            }

            [$outcome, $flagged] = LockedWrite::run(fn () => DB::transaction(function () use ($client, $link, $contacts, $stage, $repId, $invalidRep, $stats, $done) {
                if (CrmContact::unscopedForSystem()->where('client_user_id', $client->id)->exists()) {
                    return ['skipped_existing', false];
                }

                if ($link->findOrLinkContact($client)) {
                    return ['linked', false];
                }

                $contact = $contacts->create($link->clientData($client), null, [
                    'source' => 'import',
                    'stage' => $stage,
                    'assigned_rep_id' => $repId,
                    'skip_duplicate_check' => true,
                ]);

                $reasons = ['segment_not_confirmed'];
                if (! $repId) {
                    $reasons[] = $invalidRep ? 'account_rep_lacks_crm_access' : 'no_rep';
                }
                if ($stage === CrmContact::STAGE_OUTREACH) {
                    $reasons[] = 'no_shoots';
                }

                $contact->forceFill([
                    'client_user_id' => $client->id,
                    'first_shoot_date' => $stats->first_done ? substr((string) $stats->first_done, 0, 10) : null,
                    'client_type' => $stage === CrmContact::STAGE_CLIENT_POOL ? ($done > 1 ? 'recurring' : 'one_off') : null,
                    'needs_review' => true,
                    'review_reasons' => $reasons,
                ])->save();
                $link->flagSharedIdentity($contact);

                return ['created', in_array('shares_identity', $contact->review_reasons ?? [], true)];
            }), 'crm.import.client');
            $counts[$outcome]++;
            $counts['flagged'] += (int) $flagged;
        }

        $this->table(array_keys($counts), [array_values($counts)]);
        $this->info($dry ? 'Dry run: nothing was saved.' : 'Import finished.');

        return self::SUCCESS;
    }
}
