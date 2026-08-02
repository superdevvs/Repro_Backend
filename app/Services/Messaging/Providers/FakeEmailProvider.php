<?php

namespace App\Services\Messaging\Providers;

/**
 * Aggregate view over both email provider fakes.
 *
 * Lets a test assert on outbound email without caring which concrete provider a
 * given channel resolved to.
 */
class FakeEmailProvider
{
    /** @return list<array{to: mixed, subject: ?string, provider: string}> */
    public static function sent(): array
    {
        return array_merge(FakeCakemailProvider::sent(), FakeLocalSmtpProvider::sent());
    }

    /** @return list<array{to: mixed, subject: ?string, provider: string}> */
    public static function scheduled(): array
    {
        return array_merge(FakeCakemailProvider::scheduled(), FakeLocalSmtpProvider::scheduled());
    }

    public static function reset(): void
    {
        FakeCakemailProvider::reset();
        FakeLocalSmtpProvider::reset();
    }
}
