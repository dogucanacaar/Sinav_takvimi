<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // @vite yönergesi, public/build/manifest.json yoksa istisna
        // fırlatır. Testlerin `npm run build` çalıştırılmış olmasına
        // bağlı olmaması gerekir; varlıklar burada devre dışı bırakılır.
        $this->withoutVite();
    }
}
