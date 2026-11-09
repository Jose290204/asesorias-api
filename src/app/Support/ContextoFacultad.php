<?php

namespace App\Support;

// Guarda, durante UNA petición, la facultad en la que se está trabajando.
// Se registra como singleton en AppServiceProvider.
class ContextoFacultad
{
    private ?int $id = null;

    // Lo llama el middleware al inicio de cada petición autenticada
    public function establecer(?int $id): void
    {
        $this->id = $id;
    }

    // null = modo global (solo el super admin sin header)
    public function id(): ?int
    {
        return $this->id;
    }

    public function estaDefinido(): bool
    {
        return $this->id !== null;
    }
}