<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Page-render tests must not depend on a built Vite manifest —
        // CI test jobs run without `npm run build`.
        $this->withoutVite();
    }
}
