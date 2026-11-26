<?php

namespace Tests\MySql;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UpdateSemanticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_update_matches_sqlite_while_failed_conditions_still_return_zero(): void
    {
        $user = User::factory()->create(['name' => 'Matched row']);
        $this->assertSame(1, DB::table('users')->where('id', $user->id)->where('name', 'Matched row')->update(['name' => 'Matched row']));
        $this->assertSame(0, DB::table('users')->where('id', $user->id)->where('name', 'Changed elsewhere')->update(['name' => 'Must not persist']));
        $this->assertSame('Matched row', $user->fresh()->name);
    }
}
