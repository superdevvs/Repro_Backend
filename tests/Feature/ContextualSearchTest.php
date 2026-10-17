<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContextualSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['contextual-search.enabled' => true]);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_search_requires_authentication_and_can_be_disabled(): void
    {
        $this->getJson('/api/search/scopes')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        config(['contextual-search.enabled' => false]);
        $this->getJson('/api/search/scopes')->assertOk()->assertJsonPath('enabled', false);
        $this->getJson('/api/search?q=test')->assertNotFound();
    }

    public function test_accounts_match_normalized_phone_and_return_allowlisted_information(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $client = User::factory()->create(['name' => 'Search Target', 'role' => 'client', 'phone' => '+1 (202) 555-0137', 'phonenumber' => '+1 (202) 555-0137', 'company_name' => 'Search Realty']);
        $response = $this->getJson('/api/search?scope=accounts&q=2025550137')->assertOk()->assertJsonPath('groups.0.results.0.id', (string) $client->id);
        $result = $response->json('groups.0.results.0');
        $this->assertSame('Search Realty', $result['metadata']['Company']);
        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('permission_overrides', $result);
    }

    public function test_account_filters_pagination_and_preview_recheck_visibility(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $client = User::factory()->create(['name' => 'Visible Client', 'role' => 'client']);
        User::factory()->count(12)->create(['name' => 'Visible Staff', 'role' => 'admin']);
        $filters = rawurlencode(json_encode(['role' => 'client']));
        $this->getJson('/api/search?scope=accounts&q=Visible&filters='.$filters)->assertOk()->assertJsonPath('groups.0.total', 1)->assertJsonPath('groups.0.hasMore', false);
        $this->getJson('/api/search/accounts/'.$client->id.'/preview')->assertOk()->assertJsonPath('data.id', (string) $client->id);
        $client->delete();
        $this->getJson('/api/search/accounts/'.$client->id.'/preview')->assertNotFound();
    }

    public function test_sales_rep_cannot_search_or_preview_staff_and_clients_cannot_search_accounts(): void
    {
        $staff = User::factory()->create(['role' => 'admin', 'name' => 'Private Staff']);
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->getJson('/api/search?scope=accounts&q=Private%20Staff')->assertOk()->assertJsonPath('groups.0.total', 0);
        $this->getJson('/api/search/accounts/'.$staff->id.'/preview')->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->getJson('/api/search?scope=accounts&q=Private')->assertForbidden();
    }

    public function test_shoot_provider_preserves_current_tab_and_restores_outer_request(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create(['client_id' => $client->id, 'status' => 'delivered', 'workflow_status' => 'delivered', 'address' => 'Preview Target Lane']));
        $this->getJson('/api/search?scope=shoots&q=Preview%20Target')->assertOk()->assertJsonPath('groups.0.results.0.id', (string) $shoot->id);
        $filters = rawurlencode(json_encode(['tab' => 'scheduled']));
        $this->getJson('/api/search?scope=shoots&q=Preview%20Target&filters='.$filters)->assertOk()->assertJsonCount(0, 'groups.0.results');
        $this->getJson('/api/search/scopes')->assertOk()->assertJsonPath('enabled', true);
    }

    public function test_blank_and_invalid_queries_do_not_trigger_sources(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/search?q=%20%20')->assertOk()->assertJsonPath('groups', []);
        $this->getJson('/api/search?q='.str_repeat('x', 201))->assertUnprocessable();
        $this->getJson('/api/search?q=test&filters=broken')->assertUnprocessable();
        $this->getJson('/api/search?q=test&scope=unknown')->assertForbidden();
    }

    public function test_source_page_size_translation_does_not_skip_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->mock(\App\Services\Search\ReadOnlySearchSource::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnUsing(function ($request, $path, $params) {
                $all = array_map(fn ($id) => ['id' => $id, 'address' => 'Target '.$id], range(1, 12));
                return ['data' => array_slice($all, ($params['page'] - 1) * 5, 5), 'meta' => ['current_page' => $params['page'], 'per_page' => 5, 'total' => 12]];
            });
        });
        $this->getJson('/api/search?scope=shoots&q=Target')->assertOk()->assertJsonCount(8, 'groups.0.results')->assertJsonPath('groups.0.results.7.id', '8')->assertJsonPath('groups.0.hasMore', true);
        $this->getJson('/api/search?scope=shoots&q=Target&page=2')->assertOk()->assertJsonCount(4, 'groups.0.results')->assertJsonPath('groups.0.results.0.id', '9')->assertJsonPath('groups.0.hasMore', false);
    }

    public function test_everywhere_keeps_successful_groups_when_another_provider_fails(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        User::factory()->create(['role' => 'client', 'name' => 'Needle Account']);
        $this->mock(\App\Services\Search\ReadOnlySearchSource::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnUsing(function ($request, $path) {
                if ($path === '/messaging/email/messages') throw new \RuntimeException('Private diagnostic must not appear');
                return ['data' => []];
            });
        });
        $response = $this->getJson('/api/search?q=Needle')->assertOk();
        $groups = collect($response->json('groups'))->keyBy('scope');
        $this->assertSame('Needle Account', $groups['accounts']['results'][0]['title']);
        $this->assertSame('unavailable', $groups['email']['error']);
        $this->assertStringNotContainsString('Private diagnostic', $response->getContent());
    }

    public static function roleAccounts(): array
    {
        return [['admin', true], ['superadmin', true], ['editing_manager', true], ['salesRep', true], ['photographer', false], ['editor', false], ['client', false]];
    }

    #[DataProvider('roleAccounts')]
    public function test_scopes_respect_each_supported_role(string $role, bool $accountsAllowed): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $response = $this->getJson('/api/search/scopes')->assertOk();
        $this->assertSame($accountsAllowed, collect($response->json('scopes'))->contains('id', 'accounts'));
    }

    public function test_account_permission_revocation_removes_search_and_preview_access(): void
    {
        $viewer = User::factory()->create(['role' => 'admin', 'permission_overrides' => ['allow' => [], 'deny' => ['accounts-view']]]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/search?scope=accounts&q=anything')->assertForbidden();
        $this->getJson('/api/search/accounts/'.$viewer->id.'/preview')->assertForbidden();
    }

    public function test_account_rep_filter_preserves_creator_and_id_only_assignments(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $rep = User::factory()->create(['role' => 'salesRep', 'name' => 'Named Rep']);
        User::factory()->create(['role' => 'client', 'name' => 'Needle Creator', 'created_by_id' => $rep->id, 'created_by_name' => $rep->name]);
        User::factory()->create(['role' => 'client', 'name' => 'Needle Assigned', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        User::factory()->create(['role' => 'client', 'name' => 'Needle Unassigned', 'created_by_id' => null, 'created_by_name' => null, 'metadata' => []]);
        $filters = rawurlencode(json_encode(['rep' => 'named rep']));
        $this->getJson('/api/search?scope=accounts&q=Needle&filters='.$filters)->assertOk()->assertJsonPath('groups.0.total', 2);
        $this->getJson('/api/admin/users/search?search=Needle&rep=unassigned')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_photo_editor_matching_cannot_reveal_hidden_client_fields(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $client = User::factory()->create(['role' => 'client', 'name' => 'Hidden Contact Identity', 'email' => 'hidden-oracle@example.test']);
        Shoot::withoutEvents(fn () => Shoot::factory()->create(['client_id' => $client->id, 'editor_id' => $editor->id, 'address' => 'Visible Property Lane']));
        Sanctum::actingAs($editor);
        $filter = app(\App\Services\Shoots\ShootSearchFilter::class);
        $this->assertSame(0, $filter->apply(Shoot::query(), 'hidden-oracle@example.test')->count());
        $this->assertSame(0, $filter->apply(Shoot::query(), 'Hidden Contact Identity')->count());
        $this->assertSame(1, $filter->apply(Shoot::query(), 'Visible Property Lane')->count());
    }

    public function test_earnings_search_never_rebuilds_payouts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->partialMock(\App\Services\EditorPayoutService::class, function ($mock) {
            $mock->shouldNotReceive('syncPayouts');
        });
        $this->getJson('/api/search?scope=editing-earnings&q=Needle')->assertOk()->assertJsonCount(0, 'groups.0.results');
    }

    public static function summarySources(): array
    {
        return [
            ['editing-earnings', ['editor' => ['id' => 51, 'name' => 'Needle Editor', 'email' => 'needle@example.test'], 'status' => 'unpaid', 'total_earned' => 100], '51', 'Needle Editor'],
            ['expenses', ['id' => 52, 'vendor' => 'Needle Vendor', 'description' => 'Equipment', 'status' => 'paid'], '52', 'Needle Vendor'],
            ['requests', ['id' => 'request-53', 'note' => 'Needle correction', 'shoot' => ['address' => '123 Example Lane']], 'request-53', 'Needle correction'],
        ];
    }

    public function test_editing_request_search_reaches_beyond_the_legacy_fifty_row_window(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($viewer);
        $target = \App\Models\EditingRequest::create(['requester_id' => $viewer->id, 'tracking_code' => 'ER-OLD-TARGET', 'summary' => 'Needle old request', 'target_team' => 'editor', 'priority' => 'normal', 'status' => 'open']);
        $target->forceFill(['created_at' => now()->subDays(2)])->save();
        for ($i = 0; $i < 55; $i++) {
            \App\Models\EditingRequest::create(['requester_id' => $viewer->id, 'tracking_code' => 'ER-NEW-'.$i, 'summary' => 'Other request', 'target_team' => 'editor', 'priority' => 'normal', 'status' => 'open']);
        }
        $this->getJson('/api/search?scope=editing-requests&q=Needle')->assertOk()->assertJsonCount(1, 'groups.0.results')
            ->assertJsonPath('groups.0.results.0.id', (string) $target->id);
        $this->getJson('/api/search?scope=editing-requests&q=%25')->assertOk()->assertJsonCount(0, 'groups.0.results');
    }

    #[DataProvider('summarySources')]
    public function test_summary_sources_have_correct_ids_and_useful_titles(string $scope, array $row, string $id, string $title): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->mock(\App\Services\Search\ReadOnlySearchSource::class, function ($mock) use ($row) {
            $mock->shouldReceive('get')->andReturn(['data' => [$row]]);
        });
        $this->getJson('/api/search?scope='.$scope.'&q=Needle')->assertOk()
            ->assertJsonPath('groups.0.results.0.id', $id)->assertJsonPath('groups.0.results.0.title', $title);
    }
}
