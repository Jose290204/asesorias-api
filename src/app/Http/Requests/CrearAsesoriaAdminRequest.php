<?php

namespace App\Http\Requests;

// Igual que el alta del asesor, más el asesor para el que se crea la asesoría.
class CrearAsesoriaAdminRequest extends CrearAsesoriaRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Sin "exists": si no es un asesor de la facultad, el controlador responde 404
            'asesor_id' => ['required', 'integer'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'asesor_id.required' => 'Falta elegir un asesor.',
        ]);
    }
}