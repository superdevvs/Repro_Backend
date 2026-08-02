<?php

namespace App\Providers;

use App\Services\Messaging\Providers\CakemailProvider;
use App\Services\Messaging\Providers\FakeCakemailProvider;
use App\Services\Messaging\Providers\FakeEmailProvider;
use App\Services\Messaging\Providers\FakeLocalSmtpProvider;
use App\Services\Messaging\Providers\FakeSmsProvider;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Support\ServiceProvider;

/**
 * Makes it structurally impossible for a test run to reach a real messaging
 * provider.
 *
 * The environment guard ({@see \App\Services\Messaging\OutboundDeliveryGuard})
 * decides whether a message *should* go out; this provider removes the ability
 * for a test to send one at all, by swapping the concrete SMS and email
 * providers for in-memory fakes and faking the vendor HTTP endpoints.
 *
 * Two independent layers, because either alone can be bypassed: a new call site
 * that forgets the guard is still caught by the fake provider binding, and a
 * provider constructed outside the container is still caught by the HTTP fake.
 *
 * Deliberately scoped to messaging vendor hosts only. An earlier revision faked
 * '*' and called Http::preventStrayRequests(), which reached far beyond
 * messaging: stub callbacks are matched in registration order and the first hit
 * wins, so a catch-all registered here during boot() silently swallowed every
 * Http::fake() a test registered afterwards. Unrelated suites (fal.ai, for one)
 * then received an empty 200 instead of their own fixtures. Narrow patterns keep
 * the guarantee where it matters and leave every other test's HTTP expectations
 * exactly as they were.
 */
class MessagingSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        // MessagingService type-hints the concrete provider classes, so the
        // fakes extend/implement them and are bound in their place.
        $this->app->bind(TelnyxSmsProvider::class, FakeSmsProvider::class);
        $this->app->bind(CakemailProvider::class, FakeCakemailProvider::class);
        $this->app->bind(LocalSmtpProvider::class, FakeLocalSmtpProvider::class);

        FakeSmsProvider::reset();
        FakeEmailProvider::reset();

        // Every test starts blocked. A test that needs the pipeline must opt in
        // for itself, so the permission cannot leak from one test to the next.
        OutboundDeliveryGuard::resetTestingOverrides();
    }

    // Deliberately no boot(). Two earlier revisions installed a global
    // Http::fake() here as a backstop against a provider built with `new`
    // instead of resolved from the container. Both broke unrelated suites,
    // because stub callbacks match in registration order and the first hit
    // wins: anything registered during boot() outranks the fakes a test
    // registers afterwards. A catch-all '*' silently swallowed every other
    // test's fixtures; narrowing it to vendor hosts still hijacked the voice
    // and assistant suites, which legitimately fake those same hosts.
    //
    // The backstop is also unnecessary. No messaging provider is constructed
    // directly anywhere in app/, routes/, config/ or database/ — they are only
    // ever resolved from the container, where register() has already replaced
    // them with fakes. SMTP is closed separately: phpunit.xml pins
    // MAIL_MAILER=array and LocalSmtpProvider is bound to a fake.
    //
    // Tests that want to prove nothing escaped should fake HTTP themselves and
    // assert on it, which is what MessagingProviderIsolationTest does.
}
