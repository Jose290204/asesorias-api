<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Seguro: si por error se apunta a la base de desarrollo, se aborta
        // antes de que RefreshDatabase la vacíe.
        $base = config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with((string) $base, '_test')) {
            $this->fail("Peligro: las pruebas apuntan a '{$base}'. Debe terminar en _test.");
        }
    }
}