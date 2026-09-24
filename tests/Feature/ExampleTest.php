<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Uygulamanın ayağa kalktığını doğrulayan en basit kontrol.
 *
 * Ana sayfa artık giriş ister; "200 döndü mü" yerine "doğru yere
 * yönlendiriyor mu" sorulur.
 */
class ExampleTest extends TestCase
{
    public function test_ana_sayfa_girise_yonlendirir(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_saglik_ucu_yanit_verir(): void
    {
        $this->get('/up')->assertOk();
    }
}
