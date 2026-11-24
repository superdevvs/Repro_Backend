<?php

namespace Tests\Feature\Crm;

use Illuminate\Support\Facades\Route;

/**
 * Walks every CRM route that takes a contact and calls it as another rep.
 * Each must answer 404 before doing anything, whatever the verb. New routes
 * are covered automatically.
 */
class CrmIsolationMatrixTest extends CrmTestCase
{
    public function test_every_contact_route_is_404_for_another_rep(): void
    {
        $owner = $this->rep();
        $contact = $this->contactFor($owner, ['email' => 'dana@x.com', 'phone' => '3015550142']);
        $this->actAs($this->rep());

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'crm.') && str_contains($r->uri(), '{contact}'));
        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $uri = '/'.str_replace('{contact}', (string) $contact->id, $route->uri());
            if (str_contains($uri, '{')) {
                $uri = (string) preg_replace('/\{[^}]+\}/', '1', $uri);
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->json($method, $uri, [])->status();
                $this->assertSame(404, $status, "$method $uri answered $status for another rep");
            }
        }

        // The owner still gets through.
        $this->actAs($owner);
        $this->getJson("/api/crm/contacts/{$contact->id}")->assertOk();
    }

    public function test_admins_see_every_contact_route(): void
    {
        $contact = $this->contactFor($this->rep());
        $this->actAs($this->admin());

        foreach (['', '/activity', '/notes', '/events', '/quotes', '/conversation'] as $suffix) {
            $this->getJson("/api/crm/contacts/{$contact->id}{$suffix}")->assertOk();
        }
    }
}
