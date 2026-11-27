<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubirEvidenciasRequest extends FormRequest
{
    // Quien administra la asesoría de la sesión: el asesor asignado o el admin (AsesoriaPolicy::gestionar)
    public function authorize(): bool
    {
        return $this->user()->can('gestionar', $this->route('sesion')->asesoria);
    }

    // Mismas reglas que el material: 1 a 5 archivos, 20 MB cada uno
    public function rules(): array
    {
        return [
            'archivos' => ['required', 'array', 'min:1', 'max:5'],
            'archivos.*' => [
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,zip',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'archivos.required' => 'Falta elegir al menos un archivo.',
            'archivos.array' => 'Los archivos deben enviarse como una lista.',
            'archivos.min' => 'Falta elegir al menos un archivo.',
            'archivos.max' => 'Solo se pueden subir 5 archivos a la vez.',
            'archivos.*.file' => 'Uno de los archivos no es válido.',
            'archivos.*.uploaded' => 'Uno de los archivos no se pudo subir (puede ser demasiado grande).',
            'archivos.*.max' => 'Cada archivo puede pesar máximo 20 MB.',
            'archivos.*.mimes' => 'Solo se permiten PDF, Word, PowerPoint, Excel, JPG, PNG y ZIP.',
        ];
    }
}