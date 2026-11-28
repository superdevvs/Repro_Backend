<?php

namespace Tests\Unit\Database;

use App\Database\MySqlConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Long-hold and lock reports name the first application frame outside the database layer. */
class MySqlConnectionCallerTest extends TestCase
{
    public static function separators(): array
    {
        return [
            'linux' => ['/', '/srv/app'],
            'windows' => ['\\', 'C:\\work\\app'],
        ];
    }

    #[DataProvider('separators')]
    public function test_reports_the_first_frame_outside_vendor_and_the_database_layer(string $separator, string $root): void
    {
        $path = fn (string ...$parts) => $root.$separator.implode($separator, $parts);
        $frames = [
            ['file' => $path('vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Database', 'Connection.php'), 'line' => 10],
            ['file' => $path('app', 'Database', 'MySqlConnection.php'), 'line' => 20],
            ['function' => 'closure'],
            ['file' => $path('app', 'Services', 'Booking.php'), 'line' => 30],
            ['file' => $path('app', 'Http', 'Controllers', 'ShootController.php'), 'line' => 40],
        ];

        $this->assertSame('Services/Booking.php:30', (new ReflectionMethod(MySqlConnection::class, 'callerFrom'))->invoke(null, $frames));
    }
}
