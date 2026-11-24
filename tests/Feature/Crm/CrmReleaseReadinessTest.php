<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmNotice;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use App\Services\Crm\CrmWebsiteIntake;
use App\Services\Crm\CrmWebsiteIntakeReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrmReleaseReadinessTest extends CrmTestCase
{
    public function test_pending_website_intake_recovers_without_duplicate_lead_or_notice(): void
    {
        $admin = $this->admin();
        $ref = (string) Str::uuid();
        $form = [
            'first_name' => 'Morgan', 'last_name' => 'Sample', 'email' => 'morgan@example.test',
            'message' => 'Listing photography',
            'created_at' => '2026-10-30T14:30:00-04:00',
        ];
        $receipt = app(CrmWebsiteIntakeReceipt::class);
        $receipt->register('inquiry', $ref, $form);

        $original = app(CrmWebsiteIntake::class);
        $this->mock(CrmWebsiteIntake::class)->shouldReceive('inquiry')->once()->andThrow(new \RuntimeException('Simulate an interrupted intake'));
        $receipt->process('inquiry', $ref);
        $this->assertNull(DB::table('crm_website_intake_receipts')->value('completed_at'));
        $this->assertSame(0, CrmContact::query()->count());

        $this->app->instance(CrmWebsiteIntake::class, $original);
        $receipt->retryPending();
        $receipt->register('inquiry', $ref, $form);
        $receipt->process('inquiry', $ref);

        $contact = CrmContact::query()->sole();
        $this->assertSame('2026-11-02 19:30:00', $contact->first_contact_due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull(DB::table('crm_website_intake_receipts')->value('payload'));
        $this->assertSame(1, CrmNotice::query()->where('user_id', $admin->id)->where('type', 'website_inquiry')->count());
    }

    public function test_contact_review_preserves_reasons_in_one_activity_and_requires_import_permission(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $contact->forceFill(['needs_review' => true, 'review_reasons' => ['shares_identity']])->save();
        $path = '/api/crm/contacts/'.$contact->id.'/review';

        $this->actAs($rep);
        $this->postJson($path, ['note' => 'Checked contact details'])->assertForbidden();
        $this->actAs($this->admin());
        $this->postJson($path, ['note' => 'Checked contact details'])->assertOk()->assertJsonPath('contact.needs_review', false);
        $this->postJson($path, ['note' => 'Checked contact details'])->assertOk();

        $review = CrmActivity::query()->where('contact_id', $contact->id)->where('type', 'import_reviewed')->sole();
        $this->assertSame(['shares_identity'], $review->data['review_reasons']);
    }

    public function test_global_search_and_preview_use_crm_ownership_and_literal_matching(): void
    {
        config(['contextual-search.enabled' => true]);
        $rep = $this->rep();
        $own = $this->contactFor($rep, ['name' => 'Sample 10% Studio']);
        $other = $this->contactFor($this->rep(), ['name' => 'Sample 10% Brokerage']);
        $this->contactFor($rep, ['name' => 'Sample 101 Studio']);

        $this->actAs($rep);
        $this->getJson('/api/search?scope=crm-contacts&q=10%25')->assertOk()
            ->assertJsonPath('groups.0.total', 1)->assertJsonPath('groups.0.results.0.destination', '/crm/contacts/'.$own->id);
        $this->getJson('/api/search/crm-contacts/'.$own->id.'/preview')->assertOk();
        $this->getJson('/api/search/crm-contacts/'.$other->id.'/preview')->assertNotFound();
        config(['crm.enabled' => false]);
        $this->getJson('/api/search?scope=crm-contacts&q=Studio')->assertForbidden();
    }

    public function test_repeated_application_reference_reuses_the_applicant_and_attachments(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $ref = (string) Str::uuid();
        $payload = ['name' => 'Sample Applicant', 'email' => 'sample@example.test', 'role' => 'photographer', 'external_reference' => $ref];
        $service = app(\App\Services\Recruiting\RecruitApplicationIntake::class);

        $original = $service->intake($payload + ['resume' => UploadedFile::fake()->createWithContent('sample.pdf', '%PDF-1.4 fixture')], null);
        $repeat = $service->intake($payload + ['resume' => UploadedFile::fake()->createWithContent('sample.pdf', '%PDF-1.4 fixture')], null);

        $this->assertSame($original->id, $repeat->id);
        $file = RecruitApplicantFile::query()->where('applicant_id', $original->id)->sole();
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame(1, CrmNotice::query()->where('user_id', $admin->id)->where('type', 'recruit_applied')->count());
    }

    public function test_website_application_retry_recovers_a_partially_saved_attachment_set(): void
    {
        $disk = Storage::fake('local');
        $admin = $this->superAdmin();
        $ref = (string) Str::uuid();
        config(['services.external_booking.api_key' => 'synthetic-careers-retry']);
        $headers = ['Accept' => 'application/json', 'X-API-Key' => 'synthetic-careers-retry'];
        $url = '/api/external/recruiting/application';
        $resume = "%PDF-1.4\nSynthetic resume\n%%EOF";
        $license = "%PDF-1.4\nSynthetic license\n%%EOF";
        $licenseChecksum = hash('sha256', $license);
        $payload = fn () => [
            'name' => 'Sample Applicant',
            'email' => 'applicant@example.test',
            'role' => 'photographer',
            'external_reference' => $ref,
            'resume' => UploadedFile::fake()->createWithContent('resume.pdf', $resume),
            'license' => UploadedFile::fake()->createWithContent('license.pdf', $license),
        ];

        $interrupted = false;
        RecruitApplicantFile::creating(function (RecruitApplicantFile $file) use ($licenseChecksum, &$interrupted): void {
            if ($file->kind === 'license' && $file->intake_checksum === $licenseChecksum && ! $interrupted) {
                $interrupted = true;
                throw new \RuntimeException('Interrupted after storing the license bytes.');
            }
        });

        $this->post($url, $payload(), $headers)->assertStatus(500);
        $this->assertTrue($interrupted);
        $applicant = RecruitApplicant::query()->sole();
        $this->assertDatabaseHas('recruit_application_receipts', ['reference' => $ref, 'applicant_id' => $applicant->id]);
        $resumeFile = RecruitApplicantFile::query()->where('applicant_id', $applicant->id)->sole();
        $this->assertSame('resume', $resumeFile->kind);
        $directory = 'recruiting/'.$applicant->id.'/intake';
        $licensePath = $directory.'/license-'.$licenseChecksum;
        $this->assertSame($resume, $disk->get($resumeFile->path));
        $this->assertSame($license, $disk->get($licensePath));
        $this->assertEqualsCanonicalizing([$resumeFile->path, $licensePath], $disk->allFiles($directory));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post($url, $payload(), $headers)->assertCreated()
                ->assertJsonPath('applicant_id', $applicant->id)->assertJsonPath('stage', 'applied');
        }

        $this->assertDatabaseCount('recruit_applicants', 1);
        $this->assertDatabaseCount('recruit_application_receipts', 1);
        $this->assertDatabaseCount('recruit_applicant_files', 2);
        $files = RecruitApplicantFile::query()->where('applicant_id', $applicant->id)->get()->keyBy('kind');
        $this->assertCount(2, $files);
        $this->assertSame($resumeFile->id, $files['resume']->id);
        $this->assertSame($licensePath, $files['license']->path);
        $this->assertSame($licenseChecksum, $files['license']->intake_checksum);
        $this->assertSame($resume, $disk->get($files['resume']->path));
        $this->assertSame($license, $disk->get($files['license']->path));
        $this->assertEqualsCanonicalizing([$resumeFile->path, $licensePath], $disk->allFiles($directory));
        $this->assertSame(1, CrmNotice::query()->where('user_id', $admin->id)->where('type', 'recruit_applied')->count());
    }
}
