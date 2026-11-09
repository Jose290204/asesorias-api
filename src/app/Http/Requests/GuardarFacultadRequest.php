<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarFacultadRequest extends FormRequest
{
    // El rol (super admin) ya lo exige el middleware de la ruta
    public function authorize(): bool
    {
        return true;
    }

    // Las siglas se guardan siempre en mayúsculas y sin espacios en los bordes
    protected function prepareForValidation(): void
    {
        if (is_string($this->siglas)) {
            $this->merge(['siglas' => mb_strtoupper(trim($this->siglas))]);
        }

        if (is_string($this->nombre)) {
            $this->merge(['nombre' => trim($this->nombre)]);
        }
    }

    public function rules(): array
    {
        // En PUT la facultad de la ruta se ignora para poder guardar sus propias siglas
        $facultad = $this->route('facultad');

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'siglas' => ['required', 'string', 'max:20', Rule::unique('facultades', 'siglas')->ignore($facultad)],
            // max está en KB: 2048 = 2 MB
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'quitar_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Falta el nombre de la facultad.',
            'nombre.max' => 'El nombre no puede pasar de 150 caracteres.',
            'siglas.required' => 'Faltan las siglas.',
            'siglas.max' => 'Las siglas no pueden pasar de 20 caracteres.',
            'siglas.unique' => 'Ya existe una facultad con esas siglas.',
            'logo.mimes' => 'El logo debe ser PNG, JPG o WEBP.',
            'logo.max' => 'El logo no puede pesar más de 2 MB.',
            'logo.file' => 'El logo debe ser un archivo.',
        ];
    }
}