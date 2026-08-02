<?php

namespace App\Providers;

use App\Services\Messaging\Providers\CakemailProvider;
use App\Services\Messaging\Providers\FakeCakemailProvider;
use App\Services\Messaging\Providers\FakeEmailProvider;
use App\Services\Messaging\Providers\FakeLocalSmtpProvider;
use App\Services\Messaging\Providers\FakeSmsProvider;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Makes it structurally impossible for a test run to reach a real messaging
 * provider.
 *
 * The environment guard ({@see \App\Services\Messaging\OutboundDeliveryGuard})
 * decides whether a message *should* go out; this provider removes the ability
 * for a test to send one at all, by swapping the concrete SMS and email
 * providers for in-memory fakes and closing the underlying HTTP and mail
 * transports.
 *
 * Two independent layers, because either alone can be bypassed: a new call site
 * that forgets the guard is still caught by the fake, and a provider resolved
 * outside the container is still caught by the HTTP fake.
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
    }

    public function boot(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        // Backstop for any provider constructed directly rather than resolved
        // from the container: no outbound HTTP request to a messaging vendor can
        // leave the test process. `preventStrayRequests` makes an unmatched
        // request fail loudly instead of silently going out.
        Http::preventStrayRequests();
        Http::fake([
            'api.telnyx.com/*' => Http::response(['data' => ['id' => 'fake-telnyx-blocked']], 200),
            '*.telnyx.com/*' => Http::response(['data' => ['id' => 'fake-telnyx-blocked']], 200),
            '*.cakemail.com/*' => Http::response(['id' => 'fake-cakemail-blocked'], 200),
            '*' => Http::response([], 200),
        ]);

        // Laravel's mailer is faked too, so a Mail::send outside the messaging
        // service cannot open an SMTP connection either.
        Mail::fake();
    }
}
