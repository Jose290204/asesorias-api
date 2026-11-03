<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubirMaterialRequest extends FormRequest
{
    // Solo el asesor asignado sube material (AsesoriaPolicy::gestionar).
    public function authorize(): bool
    {
        return $this->user()->can('gestionar', $this->route('asesoria'));
    }

    public function rules(): array
    {
        return [
            // Entre 1 y 5 archivos por petición (en Postman: campo "archivos[]" de tipo File)
            'archivos' => ['required', 'array', 'min:1', 'max:5'],

            // Cada archivo: debe ser un archivo subido bien, de máximo 20 MB (20480 KB)
            // y de un tipo permitido. 'mimes' revisa el contenido real del archivo,
            // no solo la extensión del nombre.
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