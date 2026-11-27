<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RechazarSolicitudRequest extends FormRequest
{
    // El permiso se revisa Aaqui y no en el controlador: authorize() corre antes que rules().
    // Así un asesor ajeno recibe 403 y nunca llega a ver errores de validación (422).
    // $this->route('solicitud') ya es el modelo: el route model binding corre antes.
    public function authorize(): bool
    {
        return $this->user()->can('responder', $this->route('solicitud'));
    }

    public function rules(): array
    {
        return [
            // Obligatorio: el estudiante lo verá como "Notas del asesor"
            'motivo_rechazo' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo_rechazo.required' => 'Falta explicar el motivo del rechazo.',
            'motivo_rechazo.max' => 'El motivo no puede pasar de 500 caracteres.',
        ];
    }
}