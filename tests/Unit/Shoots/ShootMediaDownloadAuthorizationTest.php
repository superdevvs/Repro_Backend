<?php

namespace Tests\Unit\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootMediaDownloadAuthorizationTest extends TestCase
{
    #[DataProvider('salesRoles')]
    public function test_sales_downloads_require_assignment_while_existing_read_access_is_preserved(string $role): void
    {
        $actor = new User(['role' => $role]);
        $actor->id = 7;
        $shoot = new Shoot;
        $shoot->id = 42;
        $file = new ShootFile(['shoot_id' => 42, 'filename' => 'photo.jpg', 'media_type' => 'edited']);
        $policy = app(ShootAuthorizationSupport::class);

        foreach ([null, 8, 7] as $assignedRep) {
            $shoot->rep_id = $assignedRep;
            $this->assertTrue($policy->canAccessShootMedia($shoot, $actor));
            $this->assertSame($assignedRep === 7, $policy->canDownloadShootMedia($shoot, $actor));
            $this->assertSame($assignedRep === 7, $policy->canDownloadShootMediaFile($shoot, $file, $actor));
        }
    }

    public static function salesRoles(): array
    {
        return array_map(fn (string $role) => [$role], ['salesRep', 'salesrep', 'sales_rep', 'rep', 'representative']);
    }
}
