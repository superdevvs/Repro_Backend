<?php

namespace Tests\Unit\Studio;

use App\Services\Studio\Providers\FotelloException;
use App\Services\Studio\Providers\FotelloTransferUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FotelloTransferUrlTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_unsafe_locations_fail_before_any_connection(string $url): void
    {
        $this->expectException(FotelloException::class);
        (new FotelloTransferUrl)->validate($url);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://files.example.com/a.jpg', 'file:///etc/passwd', '//files.example.com/a.jpg',
            'https://127.0.0.1/a.jpg', 'https://[::1]/a.jpg', 'https://169.254.169.254/latest/meta-data',
            'https://user:secret@files.example.com/a.jpg', 'https://files.example.com:444/a.jpg',
            'https://files.example.com/a#secret', 'https://files.example.com\\@127.0.0.1/a',
            "https://files.example.com/a\r\nAuthorization: secret", 'https://localhost/a.jpg',
        ]);
    }

    #[DataProvider('unsafeAddresses')]
    public function test_dns_private_or_special_use_destinations_fail_closed(array $ips): void
    {
        $urls = new class($ips) extends FotelloTransferUrl
        {
            public function __construct(private array $ips)
            {
                parent::__construct();
            }

            protected function resolve(string $host): array
            {
                return $this->ips;
            }
        };
        $this->expectException(FotelloException::class);
        $urls->requestOptions('https://files.example.com/image.jpg');
    }

    public static function unsafeAddresses(): array
    {
        return [[['127.0.0.1']], [['10.1.2.3']], [['172.16.1.1']], [['192.168.1.1']], [['169.254.169.254']], [['100.64.0.1']], [['224.0.0.1']], [['::1']], [['::ffff:127.0.0.1']], [['fc00::1']], [['2001:db8::1']], [['8.8.8.8', '10.0.0.1']], [[]]];
    }

    public function test_optional_host_allowlist_is_exact_and_cannot_be_bypassed_by_suffixes(): void
    {
        $urls = new FotelloTransferUrl(['files.example.com']);
        $this->assertSame('files.example.com', $urls->validate('https://files.example.com/a.jpg?signature=opaque'));
        $this->expectException(FotelloException::class);
        $urls->validate('https://files.example.com.attacker.test/a.jpg');
    }

    public function test_documented_provider_response_can_use_an_unlisted_public_host_without_invented_storage_suffixes(): void
    {
        $urls = new class extends FotelloTransferUrl
        {
            protected function resolve(string $host): array
            {
                return ['8.8.4.4'];
            }
        };
        $options = $urls->requestOptions('https://new-storage.example.com/asset.jpg');
        $this->assertSame(['new-storage.example.com:443:8.8.4.4'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
    }
}
