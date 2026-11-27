<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HorariosDisponiblesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Aquí sí es obligatoria: sin fecha no hay día de la semana que consultar
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}