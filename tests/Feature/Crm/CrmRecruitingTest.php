<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmNotice;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use App\Models\Recruiting\RecruitJobPosting;
use App\Models\Recruiting\RecruitRoster;
use App\Models\User;
use App\Services\Crm\CrmBusinessTime;
use App\Services\Crm\Email\CrmSignatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CrmRecruitingTest extends CrmTestCase
{
    private function grant(User $user, array $permissions): User
    {
        $this->actAs($this->superAdmin());
        $this->putJson("/api/admin/permissions/users/{$user->id}", ['overrides' => ['allow' => $permissions, 'deny' => []]])->assertOk();

        return $user->fresh();
    }

    private function applicant(array $attributes = []): RecruitApplicant
    {
        return app(\App\Services\Recruiting\RecruitApplicantService::class)->create(array_merge([
            'role' => 'photographer', 'name' => 'Jordan Ellis', 'email' => 'jordan@example.com', 'state' => 'MD', 'source' => 'referral',
        ], $attributes), null);
    }

    public function test_recruiting_is_super_admin_only_until_granted(): void
    {
        $this->actAs($this->rep());
        $this->getJson('/api/crm/recruiting/board')->assertForbidden();
        $admin = $this->admin();
        $this->actAs($admin);
        $this->getJson('/api/crm/recruiting/board')->assertForbidden();

        $this->actAs($this->superAdmin());
        $this->getJson('/api/crm/recruiting/board')->assertOk()->assertJsonCount(6, 'columns');

        $admin = $this->grant($admin, ['crm-recruiting-view']);
        $this->actAs($admin);
        $this->getJson('/api/crm/recruiting/board')->assertOk();
        $this->getJson('/api/crm/recruiting/meta')->assertJsonPath('can.manage', false)->assertJsonPath('can.documents', false);
        $this->postJson('/api/crm/recruiting/applicants', ['role' => 'photographer', 'name' => 'X'])->assertForbidden();
    }

    public function test_recruiting_search_preserves_sqlite_backslash_pattern(): void
    {
        $backslash = $this->applicant(['name' => 'Quota\\ notes', 'email' => 'backslash@example.test']);
        $this->applicant(['name' => 'Quota% notes', 'email' => 'percent@example.test']);
        $ordinary = $this->applicant(['name' => 'Casey Photographer', 'email' => 'casey@example.test']);
        $url = '/api/crm/recruiting/applicants?q=';
        $specialQuery = rawurlencode('quota%');

        $this->actAs($this->rep());
        $this->getJson($url.$specialQuery)->assertForbidden();

        $this->actAs($this->superAdmin());
        $this->getJson($url.rawurlencode('  cAsEy  '))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ordinary->id);

        // SQLite treats the added backslash as text and the percent as a wildcard.
        $this->getJson($url.$specialQuery)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $backslash->id);
        $board = $this->getJson('/api/crm/recruiting/board?q='.$specialQuery)->assertOk();
        $this->assertSame([$backslash->id], collect($board->json('columns'))->flatMap(fn ($column) => $column['cards'])->pluck('id')->all());
    }

    public function test_stages_leave_and_reopen_and_nothing_is_deleted(): void
    {
        $this->actAs($this->superAdmin());
        $id = $this->postJson('/api/crm/recruiting/applicants', ['role' => 'sales_rep', 'name' => 'Priya Shah', 'email' => 'priya@example.com', 'states' => ['Virginia', 'dc']])
            ->assertCreated()->assertJsonPath('applicant.stage', 'applied')->assertJsonPath('applicant.states', ['VA', 'DC'])->json('applicant.id');

        $this->postJson("/api/crm/recruiting/applicants/{$id}/stage", ['stage' => 'screened'])->assertOk()->assertJsonPath('applicant.stage', 'screened');
        $this->postJson("/api/crm/recruiting/applicants/{$id}/stage", ['stage' => 'hired'])->assertUnprocessable();
        $this->postJson("/api/crm/recruiting/applicants/{$id}/exit", ['reason' => 'Took another job'])->assertOk()->assertJsonPath('applicant.stage', 'not_moving_forward');
        $this->postJson("/api/crm/recruiting/applicants/{$id}/stage", ['stage' => 'interview'])->assertUnprocessable();
        $this->postJson("/api/crm/recruiting/applicants/{$id}/reopen")->assertOk()->assertJsonPath('applicant.stage', 'screened');
        $this->getJson("/api/crm/recruiting/applicants/{$id}")->assertJsonCount(4, 'applicant.stages');

        $this->deleteJson("/api/crm/recruiting/applicants/{$id}")->assertStatus(405);
        $this->assertSame(1, RecruitApplicant::query()->count());
    }

    public function test_interview_moves_applicant_and_feedback_scores_average(): void
    {
        $super = $this->actAs($this->superAdmin());
        $a = $this->applicant();

        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/events", ['type' => 'interview', 'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'duration_min' => 30])
            ->assertCreated();
        $this->assertSame('interview', $a->fresh()->stage);
        $this->assertSame($super->id, CrmEvent::query()->where('applicant_id', $a->id)->value('assigned_user_id'));
        $this->getJson('/api/crm/follow-ups')->assertOk();

        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/notes", ['body' => 'Strong portfolio', 'scores' => ['quality' => 5, 'communication' => 4, 'reliability' => 4, 'fit' => 5]])->assertCreated();
        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/notes", ['body' => 'Trial went ok', 'scores' => ['quality' => 3, 'communication' => 4, 'reliability' => 4, 'fit' => 3]])->assertCreated();
        $this->assertSame(4.0, $a->fresh()->score);
        $this->assertEquals(4, $a->fresh()->scores['quality']);
    }

    public function test_documents_need_the_documents_permission(): void
    {
        Storage::fake('local');
        $a = $this->applicant();
        $this->actAs($this->superAdmin());
        $fileId = $this->post("/api/crm/recruiting/applicants/{$a->id}/files", ['kind' => 'resume', 'file' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('file.id');
        Storage::disk('local')->assertExists(RecruitApplicantFile::query()->findOrFail($fileId)->path);
        $this->get("/api/crm/recruiting/files/{$fileId}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $admin = $this->grant($this->admin(), ['crm-recruiting-view']);
        $this->actAs($admin);
        $this->getJson("/api/crm/recruiting/applicants/{$a->id}")->assertJsonPath('applicant.files', null)->assertJsonPath('applicant.file_count', 1);
        $this->getJson("/api/crm/recruiting/files/{$fileId}")->assertForbidden();
    }

    public function test_hiring_a_photographer_sets_up_their_account(): void
    {
        $this->actAs($this->superAdmin());
        $a = $this->applicant(['states' => ['MD', 'VA'], 'gear' => ['Sony A7 IV', 'DJI Mini 4 Pro'], 'part107_number' => 'FA1234', 'part107_expiry' => now()->addYear()->toDateString(), 'travel_radius' => 40]);

        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/hire", ['send_invite' => false])->assertUnprocessable();
        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/stage", ['stage' => 'offer'])->assertOk();
        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/hire", ['send_invite' => false])->assertOk()
            ->assertJsonPath('applicant.stage', 'hired')->assertJsonPath('account.role', 'photographer')->assertJsonPath('account.created', true);

        $user = User::query()->where('email', 'jordan@example.com')->firstOrFail();
        $this->assertSame('photographer', $user->role);
        $this->assertSame(40, $user->metadata['travel_range']);
        $states = DB::table('photographer_service_areas')->join('service_areas', 'service_areas.id', '=', 'service_area_id')->where('user_id', $user->id)->pluck('value')->sort()->values()->all();
        $this->assertSame(['MD', 'VA'], $states);
        $this->assertSame(2, DB::table('photographer_equipments')->where('photographer_id', $user->id)->where('status', 'pending_verification')->count());
        $this->assertSame('FA1234', RecruitRoster::query()->where('user_id', $user->id)->value('part107_number'));

        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/hire", ['send_invite' => false])->assertUnprocessable();

        // A sales rep hire with an email already in use must be linked on purpose.
        $rep = $this->applicant(['role' => 'sales_rep', 'name' => 'Sam Rivera', 'email' => 'jordan@example.com']);
        app(\App\Services\Recruiting\RecruitApplicantService::class)->move($rep, 'offer', null);
        $this->postJson("/api/crm/recruiting/applicants/{$rep->id}/hire", ['send_invite' => false])->assertUnprocessable();
        $this->postJson("/api/crm/recruiting/applicants/{$rep->id}/hire", ['send_invite' => false, 'link_existing' => true])->assertOk()->assertJsonPath('account.created', false);
        $this->assertContains('salesRep', $user->fresh()->secondary_roles);
    }

    public function test_public_application_lands_in_applied_and_reapplying_updates(): void
    {
        Storage::fake('local');
        $super = $this->superAdmin();
        $posting = RecruitJobPosting::create(['role' => 'photographer', 'title' => 'Real estate photographer', 'state' => 'MD', 'slug' => 'photographer-md', 'description' => 'Shoot homes', 'status' => 'open']);

        $this->getJson('/api/public/crm/careers')->assertOk()->assertJsonPath('data.0.slug', 'photographer-md');
        $payload = ['name' => 'Alex Kim', 'email' => 'Alex@Example.com', 'state' => 'MD', 'gear' => "Canon R5\nMavic 3", 'resume' => UploadedFile::fake()->create('alex.pdf', 50, 'application/pdf')];
        $this->post('/api/public/crm/careers/photographer-md/apply', $payload, ['Accept' => 'application/json'])->assertCreated();

        $a = RecruitApplicant::query()->firstOrFail();
        $this->assertSame(['applied', 'website', $posting->id, 'photographer'], [$a->stage, $a->source, $a->posting_id, $a->role]);
        $this->assertSame(['Canon R5', 'Mavic 3'], $a->gear);
        $this->assertSame(1, RecruitApplicantFile::query()->where('applicant_id', $a->id)->where('kind', 'resume')->count());
        $this->assertSame(1, CrmNotice::query()->where('user_id', $super->id)->where('type', 'recruit_applied')->count());

        $this->postJson('/api/public/crm/careers/photographer-md/apply', ['name' => 'Alex Kim', 'email' => 'alex@example.com', 'phone' => '555-0100'])->assertCreated();
        $this->assertSame(1, RecruitApplicant::query()->count());
        $this->assertSame('555-0100', $a->fresh()->phone);

        $this->postJson('/api/public/crm/careers/photographer-md/apply', ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam'])->assertCreated();
        $this->assertSame(1, RecruitApplicant::query()->count());

        $posting->forceFill(['status' => 'closed'])->save();
        $this->postJson('/api/public/crm/careers/photographer-md/apply', ['name' => 'Late', 'email' => 'late@example.com'])->assertNotFound();
        config(['crm.enabled' => false]);
        $this->getJson('/api/public/crm/careers')->assertNotFound();
    }

    public function test_expiry_reminders_are_sent_once_per_date(): void
    {
        $super = $this->superAdmin();
        $photographer = User::factory()->create(['role' => 'photographer', 'name' => 'Casey Moore']);
        RecruitRoster::create(['user_id' => $photographer->id, 'role' => 'photographer', 'part107_expiry' => now()->addDays(10)->toDateString(), 'insurance_expiry' => now()->addDays(90)->toDateString(), 'active' => true]);

        $this->artisan('crm:nightly')->assertSuccessful();
        $this->artisan('crm:nightly')->assertSuccessful();

        $notices = CrmNotice::query()->where('user_id', $super->id)->where('type', 'recruit_expiry')->get();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('Casey Moore', $notices->first()->title);
    }

    public function test_coverage_map_counts_photographers_and_pipeline(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer', 'state' => 'MD']);
        $this->applicant(['state' => 'VA', 'email' => 'va@example.com']);
        RecruitJobPosting::create(['role' => 'photographer', 'title' => 'VA shooter', 'state' => 'VA', 'slug' => 'va', 'description' => 'x', 'status' => 'open']);

        $this->actAs($this->superAdmin());
        $states = collect($this->getJson('/api/crm/recruiting/coverage')->assertOk()->json('states'))->keyBy('code');
        $this->assertSame('thin', $states['MD']['status']);
        $this->assertSame(1, $states['MD']['photographers']);
        $this->assertSame('gap', $states['VA']['status']);
        $this->assertSame(1, $states['VA']['in_pipeline']);
        $this->assertSame(1, $states['VA']['open_postings']);
        $this->assertNotNull($photographer);
    }

    public function test_recruiting_email_uses_the_senders_signature_without_unsubscribe(): void
    {
        $super = $this->actAs($this->superAdmin());
        $a = $this->applicant();
        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/email", ['subject' => 'Next steps', 'body_html' => '<p>Hi {{first_name}}</p>'])
            ->assertUnprocessable()->assertJsonPath('reason', 'signature');

        $sig = app(CrmSignatureService::class)->submit($super, ['name' => 'AJ', 'title' => 'Owner', 'sender_email' => 'aj@reprophotos.com']);
        app(CrmSignatureService::class)->approve($sig, $super);
        $this->postJson("/api/crm/recruiting/applicants/{$a->id}/email", ['subject' => 'Next steps', 'body_html' => '<p>Hi {{first_name}}</p>'])->assertCreated();

        $send = CrmEmailSend::query()->where('applicant_id', $a->id)->firstOrFail();
        $this->assertSame('recruiting', $send->source_type);
        $this->assertStringContainsString('Hi Jordan', $send->html);
        $this->assertStringNotContainsString('Unsubscribe', $send->html);
    }

    public function test_morning_digest_lists_only_the_reps_own_follow_ups(): void
    {
        // At this UTC date boundary, "today" still means the prior Eastern day.
        $this->travelTo(Carbon::parse('2026-11-25T03:00:00Z'));
        $businessNow = now(CrmBusinessTime::TIMEZONE);
        $rep = $this->rep(['email' => 'rep@reprophotos.com']);
        $other = $this->rep();
        $mine = $this->contactFor($rep, ['name' => 'Dana Whitfield']);
        $theirs = $this->contactFor($other, ['name' => 'Hidden Person']);
        app(\App\Services\Crm\CrmEventService::class)->schedule($mine, ['type' => 'call', 'starts_at' => $businessNow->copy()->setTime(11, 0)->toIso8601String()], $rep);
        // An old follow-up still assigned to $rep on a contact that moved to another rep.
        CrmEvent::create(['contact_id' => $theirs->id, 'type_id' => \App\Models\Crm\CrmEventType::query()->value('id'), 'starts_at' => $businessNow->copy()->subDay(), 'duration_min' => 15, 'assigned_user_id' => $rep->id, 'status' => 'planned']);

        $this->artisan('crm:follow-up-digest')->assertSuccessful();
        $this->artisan('crm:follow-up-digest')->assertSuccessful();

        $sends = CrmEmailSend::query()->where('source_type', 'digest')->get();
        $this->assertCount(1, $sends);
        $this->assertSame('rep@reprophotos.com', $sends->first()->to_email);
        $this->assertStringContainsString('Dana Whitfield', $sends->first()->html);
        $this->assertStringNotContainsString('Hidden Person', $sends->first()->html);
    }
}
