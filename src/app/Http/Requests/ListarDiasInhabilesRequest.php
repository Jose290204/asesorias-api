<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

class ListarDiasInhabilesRequest extends FormRequest
{
    private const MAX_DIAS = 400;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }

    // Por omisión: de hoy a un año
    public function desde(): CarbonInterface
    {
        return $this->filled('desde') ? Carbon::parse($this->input('desde'))->startOfDay() : Carbon::today();
    }

    public function hasta(): CarbonInterface
    {
        $desde = $this->desde();
        $hasta = $this->filled('hasta') ? Carbon::parse($this->input('hasta'))->endOfDay() : $desde->copy()->addYear()->endOfDay();

        return $hasta->min($desde->copy()->addDays(self::MAX_DIAS)->endOfDay());
    }
}
