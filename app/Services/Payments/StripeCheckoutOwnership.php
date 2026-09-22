<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\StripeCheckoutAttempt;
use Illuminate\Support\Facades\Log;

/** A Stripe signature authenticates the account, not the application sharing it. */
class StripeCheckoutOwnership
{
    public const OWNED = 'owned';

    public const FOREIGN = 'foreign';

    public const AMBIGUOUS = 'ambiguous';

    public const CONFLICT = 'conflict';

    public function instance(): string
    {
        $instance = trim((string) config('services.stripe.app_instance'));
        if ($instance === '' || strlen($instance) > 200) {
            throw new \RuntimeException('A stable Stripe application instance is required.');
        }

        return $instance;
    }

    public function classify(mixed $session): string
    {
        $sessionId = $this->identifier(data_get($session, 'id'));
        $intentId = $this->identifier(data_get($session, 'payment_intent'));
        $marker = trim((string) data_get($session, 'metadata.app_instance', ''));
        $linked = $this->hasLocalReference($sessionId, $intentId);
        $marked = $marker !== '' && hash_equals($this->instance(), $marker);
        $foreign = $this->isKnownForeignLegacySession($session);

        // Contradictory provenance must never choose which application gets the money.
        if (($foreign && ($linked || $marked)) || ($marker !== '' && ! $marked && $linked)) {
            return self::CONFLICT;
        }
        if ($linked || $marked) {
            return self::OWNED;
        }
        if ($foreign && $marker === '') {
            return self::FOREIGN;
        }
        if ($marker !== '') {
            return self::AMBIGUOUS;
        }

        // Pre-marker sessions remain fulfillable only with Dashboard provenance.
        // Numeric local shoot/client/attempt IDs alone can collide across applications.
        $type = (string) data_get($session, 'metadata.type', '');
        $environment = (string) data_get($session, 'metadata.environment', '');
        if (! in_array($type, ['single', 'multiple'], true)
            || data_get($session, 'metadata.company_id') !== null
            || ($environment !== '' && $environment !== app()->environment())) {
            return self::AMBIGUOUS;
        }

        $urls = $this->returnUrls($session);
        $origins = array_filter(array_map($this->origin(...), [
            (string) config('app.frontend_url'),
            (string) config('app.url'),
        ]));

        return $urls !== [] && collect($urls)->every(
            fn (string $url) => in_array($this->origin($url), $origins, true)
        ) ? self::OWNED : self::AMBIGUOUS;
    }

    public function hasLocalReference(string $sessionId, string $intentId): bool
    {
        if ($sessionId === '' && $intentId === '') {
            return false;
        }

        $attempt = StripeCheckoutAttempt::query()->where(function ($query) use ($sessionId, $intentId) {
            $query->whereRaw('1 = 0');
            if ($sessionId !== '') {
                $query->orWhere('stripe_session_id', $sessionId);
            }
            if ($intentId !== '') {
                $query->orWhere('stripe_payment_intent_id', $intentId);
            }
        })->exists();

        return $attempt || Payment::query()->where(function ($query) use ($sessionId, $intentId) {
            $query->whereRaw('1 = 0');
            if ($sessionId !== '') {
                $prefix = $sessionId.'_shoot_';
                $query->orWhere('stripe_session_id', $sessionId)
                    ->orWhereRaw('substr(stripe_session_id, 1, ?) = ?', [strlen($prefix), $prefix]);
            }
            if ($intentId !== '') {
                $query->orWhere('stripe_payment_id', $intentId);
            }
        })->exists();
    }

    public function diagnostic(string $reason, mixed $object, ?string $eventType = null, ?string $eventId = null): void
    {
        // Deliberately bounded, hash-only provider identifiers; no customer data or URLs.
        Log::channel('stripe-webhooks')->notice('Stripe webhook ownership decision.', [
            'reason' => $reason,
            'event_type' => $eventType,
            'event_hash' => $eventId ? hash('sha256', $eventId) : null,
            'object_hash' => hash('sha256', $this->identifier(data_get($object, 'id'))),
            'intent_hash' => hash('sha256', $this->identifier(data_get($object, 'payment_intent'))),
        ]);
    }

    private function isKnownForeignLegacySession(mixed $session): bool
    {
        foreach (['company_id', 'shoot_id'] as $key) {
            if (! preg_match('/\A[1-9][0-9]*\z/', (string) data_get($session, 'metadata.'.$key, ''))) {
                return false;
            }
        }

        // This exact legacy source was independently established from signed events.
        // A random non-Dashboard hostname, substring, or missing shoot is not evidence.
        $urls = $this->returnUrls($session);

        return count($urls) >= 2
            && $this->origin((string) data_get($session, 'success_url', '')) === 'https://pro.reprophotos.com:443'
            && $this->origin((string) data_get($session, 'cancel_url', '')) === 'https://pro.reprophotos.com:443'
            && collect($urls)->every(fn (string $url) => $this->origin($url) === 'https://pro.reprophotos.com:443');
    }

    private function returnUrls(mixed $session): array
    {
        return array_values(array_filter(array_map(
            fn (string $key) => (string) data_get($session, $key, ''),
            ['success_url', 'cancel_url', 'return_url']
        ), fn (string $url) => $url !== ''));
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);

        return $scheme.'://'.strtolower($parts['host']).':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    private function identifier(mixed $value): string
    {
        return is_string($value) ? trim($value) : (string) data_get($value, 'id', '');
    }
}
