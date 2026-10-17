<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ErrorDeNegocio extends Exception
{
    public function __construct(
        string $mensaje,
        // Código HTTP que recibirá el front (409 por defecto: conflicto de estado)
        public readonly int $estatus = 409,
        // Texto fijo y estable para que el front decida qué hacer sin leer el mensaje
        public readonly ?string $codigo = null,
    ) {
        parent::__construct($mensaje);
    }

    // Constructores con nombre: el service queda legible, p. ej. ErrorDeNegocio::conflicto(...)
    public static function conflicto(string $mensaje, ?string $codigo = null): self
    {
        return new self($mensaje, 409, $codigo);
    }

    public static function invalido(string $mensaje, ?string $codigo = null): self
    {
        return new self($mensaje, 422, $codigo);
    }

    // Laravel llama a render() solo cuando esta excepción llega sin atraparse,
    // así que no hace falta registrar nada en bootstrap/app.php
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'codigo' => $this->codigo,
        ], $this->estatus);
    }

    // Método vacío = no se escribe en el log. Esto no es un fallo del sistema,
    // es una regla que el usuario incumplió; si no, el log se llenaría de ruido.
    public function report(): void
    {
        //
    }
}