<?php

namespace App\Http\Requests;

use App\Enums\EstatusSolicitud;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarSolicitudesRequest extends FormRequest
{
    // El permiso lo resuelven los middlewares de la ruta (auth, activo, rol)
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Opcional; si llega, tiene que ser pendiente / aceptada / rechazada
            'estatus' => ['nullable', Rule::enum(EstatusSolicitud::class)],
        ];
    }
}