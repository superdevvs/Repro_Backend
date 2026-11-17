<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared checks for config('iguide-lab-models.reasoning') across the lab's paid reasoning families. Each family passes
 * $start() (a reserved, never-submitted record under the current setting), $process($record) (its job), $verify($record)
 * (its completed-receipt authority check) and $response() (a successful provider envelope).
 */
trait LabReasoningModelFlows
{
    private const TERRA = 'gpt-6.1-terra';

    private const TERRA_ECHO = 'gpt-6.1-terra-2026-11-01';

    /** The configured model is sent and metered, its dated echo validates, and the receipt stays valid after the setting moves on. */
    private function assertConfiguredModelFlow(callable $start, callable $process, callable $verify, callable $response, string $feature): void
    {
        config(['iguide-lab-models.reasoning' => self::TERRA]);
        $record = $start();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['model' => self::TERRA_ECHO] + $response(), 200, ['x-request-id' => 'req-terra'])]);
        $process($record); $process($record->fresh()); $record->refresh();
        $this->assertSame('completed', $record->status);
        Http::assertSentCount(1); Http::assertSent(fn ($request) => $request['model'] === self::TERRA);
        $this->assertSame([self::TERRA], array_column($record->provider_attempts, 'requestedModelId'));
        $this->assertDatabaseHas('ai_provider_usage', ['feature' => $feature, 'model' => self::TERRA, 'status' => 'success']);
        config(['iguide-lab-models.reasoning' => 'gpt-6-astra']);
        $verify($record->fresh());
        // The echo must be the model this record requested, not merely some allow-listed model.
        $attempts = $record->provider_attempts; $attempts[0]['requestedModelId'] = 'gpt-6-sol'; $record->update(['provider_attempts' => $attempts]);
        $this->assertRejected(fn () => $verify($record->fresh()));
    }

    /** A record completed with gpt-6-astra still validates, and is not resubmitted, after the setting changes. */
    private function assertHistoricalReceiptSurvivesFlip(callable $start, callable $process, callable $verify, callable $response): void
    {
        $record = $start();
        Http::fake(['api.openai.com/v1/responses' => Http::response($response(), 200, ['x-request-id' => 'req-astra'])]);
        $process($record); $process($record->fresh()); $record->refresh();
        $this->assertSame('completed', $record->status); $this->assertSame(['gpt-6-astra'], array_column($record->provider_attempts, 'requestedModelId'));
        config(['iguide-lab-models.reasoning' => self::TERRA]);
        $verify($record->fresh()); $process($record->fresh());
        Http::assertSentCount(1); Http::assertSent(fn ($request) => $request['model'] === 'gpt-6-astra');
    }

    /** A request reserved before the setting changed is sent exactly as reserved (its stored body is never recompiled with the new model). */
    private function assertReservedRequestKeepsItsModel(callable $start, callable $process, callable $verify, callable $response): void
    {
        $record = $start();
        config(['iguide-lab-models.reasoning' => self::TERRA]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($response(), 200, ['x-request-id' => 'req-reserved'])]);
        $process($record); $process($record->fresh()); $record->refresh();
        $this->assertSame('completed', $record->status);
        Http::assertSentCount(1); Http::assertSent(fn ($request) => $request['model'] === 'gpt-6-astra');
        $verify($record->fresh());
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A receipt for another model validated.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
