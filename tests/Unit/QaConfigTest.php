<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Unit coverage for the configurable QA outbound test number
 * (config/services.php -> qa.outbound_test_number).
 *
 * The config value is defined as:
 *     'outbound_test_number' => env('QA_OUTBOUND_TEST_NUMBER', '+12025550100'),
 *
 * Because env() is evaluated when the config is loaded, these tests re-evaluate
 * the services config file directly (with the env var set / unset) so the
 * assertions deterministically exercise the env-var lookup and its documented
 * default fallback rather than a one-time boot-time snapshot.
 *
 * Validates: Requirements 4.1, 4.2
 */
class QaConfigTest extends TestCase
{
    private const ENV_KEY = 'QA_OUTBOUND_TEST_NUMBER';
    private const DOCUMENTED_DEFAULT = '+12025550100';

    /**
     * Capture whether the env var was present before each test so tearDown can
     * restore the original environment and avoid leaking state across tests.
     */
    private ?string $originalEnvValue = null;
    private bool $envWasSet = false;

    protected function setUp(): void
    {
        parent::setUp();

        $current = getenv(self::ENV_KEY);
        $this->envWasSet = $current !== false;
        $this->originalEnvValue = $this->envWasSet ? $current : null;

        // Start each test from a clean slate where the env var is unset.
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        // Restore the original environment so no env var change leaks out.
        if ($this->envWasSet) {
            $this->setEnv($this->originalEnvValue);
        } else {
            $this->clearEnv();
        }

        parent::tearDown();
    }

    /**
     * Req 4.2: WHERE QA_OUTBOUND_TEST_NUMBER is unset, the backend applies the
     * documented valid default outbound test number (+12025550100).
     */
    public function test_outbound_test_number_falls_back_to_documented_default_when_env_unset(): void
    {
        // Env var is cleared in setUp(), so env() must resolve to the default.
        $this->assertFalse(
            getenv(self::ENV_KEY),
            'Precondition: QA_OUTBOUND_TEST_NUMBER should be unset for this test.'
        );

        // The booted application's config reflects the documented default.
        $this->assertSame(
            self::DOCUMENTED_DEFAULT,
            config('services.qa.outbound_test_number'),
            'config(services.qa.outbound_test_number) should fall back to the documented default.'
        );

        // Re-evaluating the config file with the env var unset yields the default,
        // confirming the fallback is defined in the config expression itself.
        $services = $this->reloadServicesConfig();

        $this->assertSame(
            self::DOCUMENTED_DEFAULT,
            $services['qa']['outbound_test_number'],
            'Re-evaluated services config should default to +12025550100 when the env var is unset.'
        );
    }

    /**
     * Req 4.1: the backend reads QA_OUTBOUND_TEST_NUMBER from the environment
     * (exposed through application configuration) when it is set.
     */
    public function test_outbound_test_number_reads_env_var_when_set(): void
    {
        $expected = '+12025550199';

        $this->setEnv($expected);

        // env() resolves the freshly-set environment variable.
        $this->assertSame(
            $expected,
            env(self::ENV_KEY),
            'env(QA_OUTBOUND_TEST_NUMBER) should resolve the set value.'
        );

        // Re-evaluating the services config file picks up the env var, proving the
        // config key is sourced from QA_OUTBOUND_TEST_NUMBER rather than a literal.
        $services = $this->reloadServicesConfig();

        $this->assertSame(
            $expected,
            $services['qa']['outbound_test_number'],
            'services.qa.outbound_test_number should read the QA_OUTBOUND_TEST_NUMBER env var.'
        );

        $this->assertNotSame(
            self::DOCUMENTED_DEFAULT,
            $services['qa']['outbound_test_number'],
            'A set env var must override the documented default.'
        );
    }

    /**
     * Re-require the services config file so its env() expressions are evaluated
     * against the current process environment.
     *
     * @return array<string, mixed>
     */
    private function reloadServicesConfig(): array
    {
        return require config_path('services.php');
    }

    /**
     * Set the QA outbound test number env var across all adapters Laravel's
     * env() reads from (getenv, $_ENV, $_SERVER).
     */
    private function setEnv(string $value): void
    {
        putenv(self::ENV_KEY . '=' . $value);
        $_ENV[self::ENV_KEY] = $value;
        $_SERVER[self::ENV_KEY] = $value;
    }

    /**
     * Remove the QA outbound test number env var from every adapter source.
     */
    private function clearEnv(): void
    {
        putenv(self::ENV_KEY);
        unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
    }
}
