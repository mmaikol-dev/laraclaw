<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaShellTest extends TestCase
{
    public function test_home_page_exposes_pwa_metadata(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('rel="manifest" href="/manifest.webmanifest"', false);
        $response->assertSee('name="theme-color" content="#0f172a"', false);
        $response->assertSee('name="apple-mobile-web-app-capable" content="yes"', false);
    }
}
