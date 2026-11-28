<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmNotice;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Producer replay (P2-03): a website application is identified by its
 * reference. A replay must carry the same fields and the same attachment
 * bytes, or it is refused for staff review and nothing is stored.
 */
class RecruitingReplayIntegrityTest extends CrmTestCase
{
    private const URL = '/api/external/recruiting/application';

    private const RESUME = "%PDF-1.4\nSynthetic resume\n%%EOF";

    private array $headers = ['Accept' => 'application/json', 'X-API-Key' => 'synthetic-careers-replay'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.external_booking.api_key' => 'synthetic-careers-replay']);
    }

    private function fields(string $reference, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sample Applicant',
            'email' => 'applicant@example.test',
            'role' => 'photographer',
            'city' => 'Rockville',
            'states' => ['MD', 'DC'],
            'travel_radius' => 40,
            'external_reference' => $reference,
        ], $overrides);
    }

    private function resume(string $bytes = self::RESUME): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('resume.pdf', $bytes);
    }

    public function test_identical_replay_returns_the_same_applicant_and_stores_no_new_file(): void
    {
        $disk = Storage::fake('local');
        $this->superAdmin();
        $reference = (string) Str::uuid();

        $first = $this->post(self::URL, $this->fields($reference) + ['resume' => $this->resume()], $this->headers)
            ->assertCreated()->json('applicant_id');
        $files = $disk->allFiles();
        $second = $this->post(self::URL, $this->fields($reference) + ['resume' => $this->resume()], $this->headers)
            ->assertCreated()->assertJsonPath('received', true)->json('applicant_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, RecruitApplicant::query()->count());
        $this->assertSame(1, RecruitApplicantFile::query()->count());
        $this->assertSame($files, $disk->allFiles());
        $receipt = DB::table('recruit_application_receipts')->where('reference', $reference)->sole();
        $this->assertSame(64, strlen((string) $receipt->payload_sha256));
        $this->assertSame(['resume:'.hash('sha256', self::RESUME)], json_decode((string) $receipt->attachment_sha256s, true));
    }

    public function test_replay_with_changed_fields_is_refused_and_changes_nothing(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $reference = (string) Str::uuid();
        $this->post(self::URL, $this->fields($reference) + ['resume' => $this->resume()], $this->headers)->assertCreated();
        $applicant = RecruitApplicant::query()->sole();
        $before = $applicant->getAttributes();
        $receiptBefore = (array) DB::table('recruit_application_receipts')->where('reference', $reference)->sole();

        $this->post(self::URL, $this->fields($reference, ['city' => 'Baltimore']) + ['resume' => $this->resume()], $this->headers)
            ->assertStatus(409)
            ->assertJsonPath('received', false)->assertJsonPath('error', 'reference_conflict');

        $this->assertSame($before, $applicant->fresh()->getAttributes());
        $this->assertSame($receiptBefore, (array) DB::table('recruit_application_receipts')->where('reference', $reference)->sole());
        $this->assertSame(1, RecruitApplicantFile::query()->count());
        $notice = CrmNotice::query()->where('user_id', $admin->id)->where('type', 'recruit_reference_conflict')->sole();
        $this->assertSame($applicant->id, $notice->data['applicant_id']);
        $this->assertStringNotContainsString('Baltimore', json_encode($notice->toArray()));
    }

    public function test_replay_with_changed_attachment_bytes_is_refused_without_storing_the_file(): void
    {
        $disk = Storage::fake('local');
        $this->superAdmin();
        $reference = (string) Str::uuid();
        $this->post(self::URL, $this->fields($reference) + ['resume' => $this->resume()], $this->headers)->assertCreated();
        $files = $disk->allFiles();

        $this->post(self::URL, $this->fields($reference) + ['resume' => $this->resume("%PDF-1.4\nEdited resume\n%%EOF")], $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'reference_conflict');
        $this->post(self::URL, $this->fields($reference), $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'reference_conflict');

        $this->assertSame($files, $disk->allFiles());
        $this->assertSame(1, RecruitApplicantFile::query()->count());
        $this->assertSame(hash('sha256', self::RESUME), RecruitApplicantFile::query()->value('intake_checksum'));
    }

    public function test_a_receipt_from_before_hashing_is_refused_for_review(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $reference = (string) Str::uuid();
        $this->post(self::URL, $this->fields($reference), $this->headers)->assertCreated();
        DB::table('recruit_application_receipts')->where('reference', $reference)
            ->update(['payload_sha256' => null, 'attachment_sha256s' => null]);

        $this->post(self::URL, $this->fields($reference), $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'reference_conflict');

        $this->assertNull(DB::table('recruit_application_receipts')->where('reference', $reference)->value('payload_sha256'));
        $this->assertSame(1, CrmNotice::query()->where('user_id', $admin->id)->where('type', 'recruit_reference_conflict')->count());
    }

    public function test_field_order_and_unknown_fields_do_not_change_the_identity(): void
    {
        Storage::fake('local');
        $this->superAdmin();
        $reference = (string) Str::uuid();
        $this->post(self::URL, $this->fields($reference), $this->headers)->assertCreated();

        $reordered = array_reverse($this->fields($reference), true) + ['attempt' => 3];
        $this->post(self::URL, $reordered, $this->headers)->assertCreated();

        $this->assertSame(1, RecruitApplicant::query()->count());
    }
}
