<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 11: Upload validation partitions files correctly.
 *
 * For every generated set of candidate files submitted to `POST /api/studio/uploads`,
 * each file that violates the selected Workflow's type or size constraint must be
 * rejected together with the exact set of violated constraints, every conforming
 * file must be accepted and persisted, and the accepted plus rejected results must
 * partition the submitted set exactly (no duplicates, no dropped files).
 *
 * **Validates: Requirements 4.7**
 *
 * PHPUnit has no PBT library configured, so a fixed seed drives 24 reproducible
 * cases: four per workflow, spread evenly across all six workflows. Each of the four
 * forced case shapes guarantees coverage of all-conforming sets, all-violating sets,
 * single-constraint violations (unsupported extension, unsupported MIME, oversize),
 * combined violations, and the exact size-limit boundary (both alone in an accepted
 * set and inside a mixed set) rather than leaving those dimensions to chance. Expectations
 * are derived independently from `config/studio_uploads.php`, and files are matched
 * by unique filename rather than by substring or position.
 *
 * @group ai-editing-studio-revamp
 */
class StudioUploadValidationPartitionPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/uploads';
    private const ITERATIONS = 24;
    private const SEED = 20260801;
    private const TEAM = 311;

    private const WORKFLOWS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];

    /** Extension => MIME pairs that are genuinely consistent with each other. */
    private const CANDIDATE_PAIRS = [
        ['jpg', 'image/jpeg'],
        ['jpeg', 'image/jpeg'],
        ['png', 'image/png'],
        ['gif', 'image/gif'],
        ['webp', 'image/webp'],
        ['tiff', 'image/tiff'],
        ['heic', 'image/heic'],
        ['nef', 'image/x-nikon-nef'],
        ['cr2', 'image/x-canon-cr2'],
        ['dng', 'image/x-adobe-dng'],
        ['raw', 'application/octet-stream'],
        ['mp4', 'video/mp4'],
        ['mov', 'video/quicktime'],
        ['webm', 'video/webm'],
        ['mkv', 'video/x-matroska'],
    ];

    /** Extensions used to force an unsupported-extension violation. */
    private const FOREIGN_EXTENSIONS = ['pdf', 'txt', 'zip', 'docx', 'psd', 'mp3', 'jpg', 'mp4', 'nef'];

    /** Non-executable MIME types used to force an unsupported-MIME violation. */
    private const FOREIGN_MIMES = [
        'text/plain',
        'application/pdf',
        'application/zip',
        'audio/mpeg',
        'image/bmp',
        'image/jpeg',
        'video/mp4',
        'application/octet-stream',
    ];

    public function test_property_11_upload_validation_partitions_files_correctly(): void
    {
        Storage::fake('public');
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['team_id' => self::TEAM],
        ]);
        Sanctum::actingAs($editor);

        mt_srand(self::SEED);
        $coverage = array_fill_keys([
            'conforming', 'sizeAtLimit', 'extensionOnly', 'mimeOnly', 'sizeOnly',
            'extensionAndMime', 'allConstraints', 'allConformingSet', 'allViolatingSet',
            'mixedSet',
        ], false);
        $workflowCoverage = array_fill_keys(self::WORKFLOWS, false);

        for ($case = 0; $case < self::ITERATIONS; $case++) {
            $workflow = self::WORKFLOWS[$case % count(self::WORKFLOWS)];
            $constraints = (array) config("studio_uploads.workflows.{$workflow}");
            $this->assertNotSame([], $constraints, "Missing constraints for {$workflow}.");
            $workflowCoverage[$workflow] = true;

            $specs = $this->generateFiles($case, $workflow, $constraints);
            $expectedAccepted = [];
            $expectedRejected = [];

            foreach ($specs as $spec) {
                $coverage[$spec['label']] = true;
                $violated = $this->expectedViolations($spec, $constraints);
                if ($violated === []) {
                    $expectedAccepted[] = $spec['name'];
                } else {
                    $expectedRejected[$spec['name']] = $violated;
                }
            }

            $coverage[$this->setLabel($expectedAccepted, $expectedRejected)] = true;

            $response = $this->post(self::URL, [
                'workflow' => $workflow,
                'files' => array_map(
                    static fn (array $spec): UploadedFile => UploadedFile::fake()
                        ->create($spec['name'], $spec['kilobytes'], $spec['mime']),
                    $specs
                ),
            ]);

            $accepted = (array) $response->json('data.accepted');
            $rejected = (array) $response->json('data.rejected');
            $acceptedNames = array_map(static fn (array $entry): string => $entry['filename'], $accepted);
            $rejectedNames = array_map(static fn (array $entry): string => $entry['filename'], $rejected);
            $actualRejections = [];
            foreach ($rejected as $entry) {
                $actualRejections[$entry['filename']] = $this->constraintNames($entry['violations']);
            }

            $counterexample = sprintf(
                "Property 11 counterexample: seed=%d case=%d workflow=%s\nfiles=%s\nexpectedAccepted=%s\nactualAccepted=%s\nexpectedRejected=%s\nactualRejected=%s",
                self::SEED,
                $case,
                $workflow,
                json_encode($this->describe($specs, $constraints), JSON_THROW_ON_ERROR),
                json_encode($expectedAccepted, JSON_THROW_ON_ERROR),
                json_encode($acceptedNames, JSON_THROW_ON_ERROR),
                json_encode($expectedRejected, JSON_THROW_ON_ERROR),
                json_encode($actualRejections, JSON_THROW_ON_ERROR)
            );

            $response->assertStatus($expectedAccepted === [] ? 422 : 201);
            $response->assertJsonPath('success', $expectedAccepted !== []);

            // Every violating file is rejected with exactly its violated constraints.
            $this->assertEqualsCanonicalizing(
                array_keys($expectedRejected),
                $rejectedNames,
                $counterexample
            );
            foreach ($expectedRejected as $name => $violated) {
                $this->assertEqualsCanonicalizing(
                    $violated,
                    $actualRejections[$name] ?? [],
                    $counterexample
                );
            }

            // Every conforming file is accepted and actually persisted.
            $this->assertEqualsCanonicalizing($expectedAccepted, $acceptedNames, $counterexample);
            foreach ($accepted as $entry) {
                $this->assertSame($workflow, $entry['workflow'], $counterexample);
                $this->assertTrue(
                    Storage::disk('public')->exists($entry['storagePath']),
                    $counterexample
                );
            }

            // Accepted + rejected exactly partitions the submitted set.
            $submitted = array_map(static fn (array $spec): string => $spec['name'], $specs);
            $partition = array_merge($acceptedNames, $rejectedNames);
            $this->assertCount(count($submitted), $partition, $counterexample);
            $this->assertEqualsCanonicalizing($submitted, $partition, $counterexample);
            $this->assertSame([], array_intersect($acceptedNames, $rejectedNames), $counterexample);
            $this->assertSame(count($accepted), (int) $response->json('meta.acceptedCount'), $counterexample);
            $this->assertSame(count($rejected), (int) $response->json('meta.rejectedCount'), $counterexample);
        }

        foreach ($coverage as $dimension => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$dimension}.");
        }
        foreach ($workflowCoverage as $workflow => $seen) {
            $this->assertTrue($seen, "Generator did not cover workflow {$workflow}.");
        }
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @return list<array<string, mixed>>
     */
    private function generateFiles(int $case, string $workflow, array $constraints): array
    {
        $group = intdiv($case, count(self::WORKFLOWS));

        // Forced cases: every violation shape and every set shape, per workflow.
        // Four groups only (ITERATIONS / 6), so the former standalone sizeAtLimit and
        // extensionOnly shapes are folded into groups 0-3.
        $forced = match ($group) {
            0 => ['conforming', 'conforming', 'sizeAtLimit'],
            1 => ['extensionOnly', 'mimeOnly', 'sizeOnly'],
            2 => ['extensionAndMime', 'allConstraints', 'extensionOnly'],
            3 => ['conforming', 'extensionOnly', 'sizeAtLimit', 'sizeOnly', 'allConstraints'],
            default => null,
        };

        $labels = $forced ?? $this->randomLabels();
        $files = [];
        foreach ($labels as $index => $label) {
            $files[] = $this->fileSpec($case, $index, $label, $workflow, $constraints);
        }

        return $files;
    }

    /** @return list<string> */
    private function randomLabels(): array
    {
        $labels = [
            'conforming', 'conforming', 'sizeAtLimit', 'extensionOnly',
            'mimeOnly', 'sizeOnly', 'extensionAndMime', 'allConstraints',
        ];
        $count = mt_rand(1, 5);
        $selected = [];
        for ($index = 0; $index < $count; $index++) {
            $selected[] = $labels[mt_rand(0, count($labels) - 1)];
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @return array<string, mixed>
     */
    private function fileSpec(
        int $case,
        int $index,
        string $label,
        string $workflow,
        array $constraints
    ): array {
        $maxKilobytes = intdiv((int) $constraints['max_bytes'], 1024);
        [$extension, $mime] = $this->conformingPair($constraints);

        $kilobytes = match ($label) {
            'sizeAtLimit' => $maxKilobytes,
            'sizeOnly', 'allConstraints' => $maxKilobytes + 1,
            default => mt_rand(1, 64),
        };

        if (in_array($label, ['extensionOnly', 'extensionAndMime', 'allConstraints'], true)) {
            $extension = $this->foreignExtension($constraints);
        }
        if (in_array($label, ['mimeOnly', 'extensionAndMime', 'allConstraints'], true)) {
            $mime = $this->foreignMime($constraints);
        }

        return [
            'label' => $label,
            'name' => sprintf('case%d-file%d-%s.%s', $case, $index, $workflow, $extension),
            'extension' => $extension,
            'mime' => $mime,
            'kilobytes' => $kilobytes,
            'bytes' => $kilobytes * 1024,
        ];
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @return array{string, string}
     */
    private function conformingPair(array $constraints): array
    {
        $pairs = array_values(array_filter(
            self::CANDIDATE_PAIRS,
            static fn (array $pair): bool => in_array($pair[0], (array) $constraints['extensions'], true)
                && in_array($pair[1], (array) $constraints['mimes'], true)
        ));
        $this->assertNotSame([], $pairs, 'No conforming extension/MIME pair available.');

        return $pairs[mt_rand(0, count($pairs) - 1)];
    }

    /** @param array<string, mixed> $constraints */
    private function foreignExtension(array $constraints): string
    {
        $candidates = array_values(array_filter(
            self::FOREIGN_EXTENSIONS,
            static fn (string $extension): bool => !in_array($extension, (array) $constraints['extensions'], true)
        ));
        $this->assertNotSame([], $candidates, 'No unsupported extension available.');

        return $candidates[mt_rand(0, count($candidates) - 1)];
    }

    /** @param array<string, mixed> $constraints */
    private function foreignMime(array $constraints): string
    {
        $candidates = array_values(array_filter(
            self::FOREIGN_MIMES,
            static fn (string $mime): bool => !in_array($mime, (array) $constraints['mimes'], true)
        ));
        $this->assertNotSame([], $candidates, 'No unsupported MIME type available.');

        return $candidates[mt_rand(0, count($candidates) - 1)];
    }

    /**
     * The independent oracle: constraints violated by a file, derived from config.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $constraints
     * @return list<string>
     */
    private function expectedViolations(array $spec, array $constraints): array
    {
        $violations = [];
        if (!in_array($spec['extension'], (array) $constraints['extensions'], true)) {
            $violations[] = 'extension';
        }
        if (!in_array($spec['mime'], (array) $constraints['mimes'], true)) {
            $violations[] = 'mime';
        }
        if ($spec['bytes'] > (int) $constraints['max_bytes']) {
            $violations[] = 'size';
        }

        return $violations;
    }

    /** @param list<array<string, mixed>> $violations @return list<string> */
    private function constraintNames(array $violations): array
    {
        return array_values(array_map(
            static fn (array $violation): string => (string) $violation['constraint'],
            $violations
        ));
    }

    /**
     * @param  list<string>  $accepted
     * @param  array<string, list<string>>  $rejected
     */
    private function setLabel(array $accepted, array $rejected): string
    {
        if ($rejected === []) {
            return 'allConformingSet';
        }

        return $accepted === [] ? 'allViolatingSet' : 'mixedSet';
    }

    /**
     * @param  list<array<string, mixed>>  $specs
     * @param  array<string, mixed>  $constraints
     * @return list<array<string, mixed>>
     */
    private function describe(array $specs, array $constraints): array
    {
        return array_map(fn (array $spec): array => [
            'name' => $spec['name'],
            'extension' => $spec['extension'],
            'mime' => $spec['mime'],
            'bytes' => $spec['bytes'],
            'maxBytes' => (int) $constraints['max_bytes'],
            'expectedViolations' => $this->expectedViolations($spec, $constraints),
        ], $specs);
    }
}
