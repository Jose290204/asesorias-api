<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

// Todas las pruebas Feature usan TestCase y reconstruyen la base de prueba
pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');