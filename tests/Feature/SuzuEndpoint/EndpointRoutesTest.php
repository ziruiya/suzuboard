<?php

namespace Tests\Feature\SuzuEndpoint;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EndpointRoutesTest extends TestCase
{
    public function test_suzu_endpoint_admin_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());
        foreach ([
            'suzu/endpoint/contracts',
            'suzu/endpoint/contract',
            'suzu/endpoint/snapshot',
            'suzu/endpoint/binding',
            'suzu/endpoint/preflight',
            'suzu/endpoint/cutover',
            'suzu/endpoint/rollback',
            'suzu/endpoint/changes',
        ] as $suffix) {
            $this->assertTrue(
                $uris->contains(fn ($uri) => str_ends_with($uri, $suffix)),
                "route {$suffix} is not registered"
            );
        }
    }
}
