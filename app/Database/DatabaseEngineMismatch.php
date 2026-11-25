<?php

namespace App\Database;

use RuntimeException;

/** The default connection uses a different engine than DB_EXPECTED_ENGINE names. */
final class DatabaseEngineMismatch extends RuntimeException
{
    public static function for(string $connection, string $actual, string $expected): self
    {
        return new self(sprintf(
            'Database connection [%s] uses %s but DB_EXPECTED_ENGINE is %s. No query was run.',
            $connection, $actual, $expected
        ));
    }
}
