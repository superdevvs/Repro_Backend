<?php

namespace App\Services\ListingStudio;

use App\Exceptions\PublicApiException;
use App\Exceptions\PublicApiResponseException;
use App\Models\ListingStudioTrialClaim;
use App\Models\User;
use App\Services\SupportAttachmentService;
use App\Services\SupportTicketService;
use App\Services\Users\EmailVerificationPilot;
use App\Support\LockedWrite;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** A once-only, team-fulfilled offer. It never grants Studio access or paid credits. */
class ListingStudioTrialService
{
    public const EDIT_TYPES = [
        'virtual_staging' => 'Virtual staging',
        'decluttering' => 'Digital decluttering',
        'green_grass' => 'Green grass',
        'twilight' => 'Digital dusk / twilight',
    ];

    public const PHOTO_COUNT = 3;

    public const MAX_FILE_KB = 10240;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private SupportTicketService $tickets, private EmailVerificationPilot $verification) {}

    public function status(User $user): array
    {
        $this->authorizeClient($user);
        $claim = ListingStudioTrialClaim::where('client_id', $user->id)->with('ticket')->first();
        $verified = $this->verification->verified($user);
        $reason = $claim ? 'already_claimed' : (! $verified ? 'email_not_verified' : null);
        if ($reason === null && ListingStudioTrialClaim::where('email_hash', $this->emailHash($user))->exists()) {
            $reason = 'email_already_claimed';
        }

        return [
            'eligible' => $reason === null, 'reason' => $reason, 'email_verified' => $verified,
            'offer' => [
                'photo_count' => self::PHOTO_COUNT, 'max_file_size_bytes' => self::MAX_FILE_KB * 1024,
                'allowed_mime_types' => self::MIME_TYPES,
                'edit_types' => collect(self::EDIT_TYPES)->map(fn ($label, $code) => compact('code', 'label'))->values()->all(),
            ],
            'claim' => $claim ? $this->present($claim) : null,
        ];
    }

    public function authorizeClient(User $user): void
    {
        if ($user->role !== 'client' || ! $user->isAccountEligibleForAuthentication()) {
            throw new PublicApiException('This introductory offer is for active client accounts.', 'trial_client_required', 403);
        }
    }

    public function requireVerified(User $user): void
    {
        $this->authorizeClient($user);
        if (! $this->verification->verified($user)) {
            throw new PublicApiException('Verify your current email address before requesting your free photo edits.', 'email_not_verified', 403);
        }
    }

    /** Attachments have already been validated and saved privately outside this short transaction. */
    public function claim(User $user, array $data): array
    {
        $hash = hash('sha256', json_encode([
            'edit_types' => $data['edit_types'], 'notes' => $data['notes'],
            'attachments' => SupportAttachmentService::fingerprint($data['attachments_json']),
        ], JSON_THROW_ON_ERROR));

        try {
            return LockedWrite::run(fn () => DB::transaction(function () use ($user, $data, $hash) {
                // Recheck account/email proof after uploads, before committing the claim.
                $client = User::findOrFail($user->id);
                $this->requireVerified($client);
                if ($existing = ListingStudioTrialClaim::where('client_id', $client->id)->first()) {
                    return $this->replay($existing, $data['trial_request_key'], $hash);
                }
                if (ListingStudioTrialClaim::where('email_hash', $this->emailHash($client))->exists()) {
                    $this->alreadyClaimed();
                }

                $instructions = ['Listing Studio introductory offer: 3 free photo edits.',
                    'Team fulfillment: apply one selected edit to each original photo and return the three edited photos as private attachments in this request.',
                    'No subscription or payment is required. No monthly credits are used.', ''];
                foreach ($data['attachments_json'] as $index => $file) {
                    $instructions[] = ($index + 1).'. '.$file['name'].' — '.self::EDIT_TYPES[$data['edit_types'][$index]];
                }
                if ($data['notes'] !== '') {
                    $instructions[] = "\nClient notes:\n".$data['notes'];
                }
                $ticket = $this->tickets->create($client, [
                    'request_key' => $data['request_key'], 'subject' => 'Listing Studio — 3 free photo edits',
                    'body' => implode("\n", $instructions), 'category' => 'other',
                    'page_path' => '/listing-studio/free-edits', 'attachments_json' => $data['attachments_json'],
                ]);
                $claim = ListingStudioTrialClaim::create([
                    'client_id' => $client->id, 'email_hash' => $this->emailHash($client),
                    'support_ticket_id' => $ticket->id, 'request_key' => $data['trial_request_key'],
                    'request_hash' => $hash, 'edit_types' => $data['edit_types'],
                ]);
                $claim->setRelation('ticket', $ticket);

                return ['claim' => $this->present($claim), 'created' => true];
            }), 'listing-studio-trial.claim');
        } catch (UniqueConstraintViolationException $exception) {
            // Database uniqueness is authoritative when another request wins the race.
            if ($existing = ListingStudioTrialClaim::where('client_id', $user->id)->first()) {
                return $this->replay($existing, $data['trial_request_key'], $hash);
            }
            if (ListingStudioTrialClaim::where('email_hash', $this->emailHash($user->fresh()))->exists()) {
                $this->alreadyClaimed();
            }
            throw $exception;
        }
    }

    private function replay(ListingStudioTrialClaim $claim, string $key, string $hash): array
    {
        if ($claim->request_key === $key && hash_equals($claim->request_hash, $hash)) {
            return ['claim' => $this->present($claim), 'created' => false];
        }
        $this->alreadyClaimed($claim);
    }

    private function alreadyClaimed(?ListingStudioTrialClaim $claim = null): never
    {
        throw new PublicApiResponseException(response()->json([
            'message' => 'This one-time offer has already been requested.', 'code' => 'trial_already_claimed',
            'data' => $claim ? $this->present($claim) : null,
        ], 409));
    }

    private function emailHash(User $user): string
    {
        return hash('sha256', mb_strtolower(trim($user->email)));
    }

    private function present(ListingStudioTrialClaim $claim): array
    {
        $claim->loadMissing('ticket');

        return ['id' => $claim->id, 'ticket_id' => $claim->support_ticket_id, 'status' => $claim->ticket->status,
            'support_url' => '/messaging/email/inbox?tab=support&ticket='.$claim->support_ticket_id];
    }
}
