<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelarAsesoriaRequest extends FormRequest
{
    // El permiso se revisa aquí y no en el controlador: authorize() corre antes que rules().
    // Así quien no es el asesor asignado recibe 403 y nunca ve errores de validación (422).
    // $this->route('asesoria') ya es el modelo: el route model binding corre antes.
    public function authorize(): bool
    {
        return $this->user()->can('gestionar', $this->route('asesoria'));
    }

    public function rules(): array
    {
        return [
            // Obligatorio: el estudiante lo verá en su historial como asesoría "Cancelada"
            'motivo_cancelacion' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo_cancelacion.required' => 'Falta explicar el motivo de la cancelación.',
            'motivo_cancelacion.max' => 'El motivo no puede pasar de 500 caracteres.',
        ];
    }
}