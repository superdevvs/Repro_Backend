<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitRoster;
use App\Models\ServiceArea;
use App\Models\User;
use App\Services\Crm\CrmNoticeService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hire hand-off: a sales rep gets a salesRep account (and so the CRM); a
 * photographer gets a photographer account with their service areas, gear
 * (pending verification) and licence and insurance expiry dates.
 */
class RecruitHireService
{
    public function __construct(
        private readonly RecruitApplicantService $applicants,
        private readonly CrmNoticeService $notices,
    ) {}

    /**
     * @param  array{link_existing?: bool, send_invite?: bool, start_date?: string|null}  $options
     * @return array{user: User, roster: RecruitRoster, created: bool}
     */
    public function hire(RecruitApplicant $applicant, User $actor, array $options = []): array
    {
        if ($applicant->stage === 'hired') {
            throw ValidationException::withMessages(['stage' => 'This person has already been hired.']);
        }
        if ($applicant->stage !== 'offer') {
            throw ValidationException::withMessages(['stage' => 'Move them to Offer before hiring.']);
        }
        $email = strtolower(trim((string) $applicant->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Add a valid email address before hiring; it becomes their sign-in.']);
        }

        $role = $applicant->role === 'sales_rep' ? 'salesRep' : 'photographer';
        $existing = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing && ! ($options['link_existing'] ?? false)) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists ('.$existing->name.', '.$existing->role.'). Link it instead of creating a new one.']);
        }
        if ($existing && $existing->trashed()) {
            throw ValidationException::withMessages(['email' => 'The account with this email was deleted. Restore it from Accounts first.']);
        }

        $result = LockedWrite::run(fn () => DB::transaction(function () use ($applicant, $actor, $role, $email, $existing, $options) {
            $created = false;
            $user = $existing;
            if (! $user) {
                $user = User::create([
                    'name' => $applicant->name,
                    'username' => $this->username($applicant->name, $email),
                    'email' => $email,
                    'phone' => $applicant->phone,
                    'phonenumber' => $applicant->phone,
                    'city' => $applicant->city,
                    'state' => $applicant->state,
                    'role' => $role,
                    'account_status' => User::ACCOUNT_STATUS_ACTIVE,
                    'password' => Hash::make(Str::random(48)),
                    'metadata' => array_filter([
                        'travel_range' => $applicant->travel_radius,
                        'travel_range_unit' => $applicant->travel_radius ? 'miles' : null,
                        'recruitedFromApplicantId' => $applicant->id,
                        'startDate' => $options['start_date'] ?? null,
                    ], fn ($v) => $v !== null),
                ]);
                $created = true;
            } elseif ($existing->role !== $role && ! in_array($role, $existing->secondary_roles ?? [], true)) {
                $existing->forceFill(['secondary_roles' => array_values(array_unique(array_merge($existing->secondary_roles ?? [], [$role])))])->save();
            }

            $states = collect($applicant->states ?? [])->push($applicant->state)->map(fn ($s) => RecruitCoverage::stateCode($s))->filter()->unique()->values()->all();
            if ($role === 'photographer') {
                $this->attachServiceAreas($user, $states);
                $this->recordGear($user, $applicant->gear ?? []);
            }

            $roster = RecruitRoster::query()->updateOrCreate(['user_id' => $user->id], [
                'role' => $applicant->role,
                'applicant_id' => $applicant->id,
                'states' => $states,
                'metros' => $applicant->metros,
                'travel_radius' => $applicant->travel_radius,
                'gear' => $applicant->gear,
                'part107_number' => $applicant->part107_number,
                'part107_expiry' => $applicant->part107_expiry,
                'insurance_expiry' => $applicant->insurance_expiry,
                'active' => true,
            ]);

            $applicant->forceFill(['hired_user_id' => $user->id])->save();
            $this->applicants->transition($applicant, 'hired', $actor, $created ? 'Account created' : 'Linked to existing account');

            return ['user' => $user, 'roster' => $roster, 'created' => $created];
        }), 'recruit.applicant.hire');

        if ($result['created'] && ($options['send_invite'] ?? true)) {
            $user = $result['user'];
            DB::afterCommit(function () use ($user, $actor) {
                try {
                    app(AccountCreatedNotificationService::class)->dispatch($user, [
                        'actor' => $actor,
                        'issued_context' => 'recruiting_hire',
                        'include_password_creation_link' => true,
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        $this->notices->notifyRecruiters('recruit_hired', $applicant->name.' hired as '.$this->applicants->roleLabel($applicant->role), null, ['applicant_id' => $applicant->id, 'user_id' => $result['user']->id]);

        return $result;
    }

    private function attachServiceAreas(User $user, array $states): void
    {
        if (! Schema::hasTable('service_areas') || ! Schema::hasTable('photographer_service_areas')) {
            return;
        }
        foreach ($states as $code) {
            $area = ServiceArea::query()->firstOrCreate(['kind' => ServiceArea::KIND_STATE, 'value' => $code], ['label' => RecruitCoverage::STATES[$code] ?? $code]);
            DB::table('photographer_service_areas')->updateOrInsert(
                ['user_id' => $user->id, 'service_area_id' => $area->id],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /** Gear goes in as pending verification, the same state the account form uses. */
    private function recordGear(User $user, array $gear): void
    {
        if ($gear === [] || ! Schema::hasTable('photographer_equipments')) {
            return;
        }
        foreach (array_slice($gear, 0, 30) as $name) {
            $exists = DB::table('photographer_equipments')->where('photographer_id', $user->id)->where('name', $name)->exists();
            if (! $exists) {
                DB::table('photographer_equipments')->insert([
                    'photographer_id' => $user->id, 'name' => mb_substr((string) $name, 0, 255), 'status' => 'pending_verification',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function username(string $name, string $email): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($name)), '.') ?: Str::before($email, '@');
        $base = trim((string) preg_replace('/[^a-z0-9._-]/', '', $base), '.') ?: 'user';
        $candidate = $base;
        $n = 1;
        while (User::withTrashed()->where('username', $candidate)->exists()) {
            $candidate = $base.'.'.$n++;
        }

        return $candidate;
    }
}
