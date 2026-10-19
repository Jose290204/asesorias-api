<?php

namespace App\Http\Requests;

use App\Enums\Dia;
use App\Enums\Modalidad;
use App\Models\Materia;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class GuardarDisponibilidadRequest extends FormRequest
{
    // El permiso ya lo resuelven los middlewares de la ruta, así que aquí siempre true
    public function authorize(): bool
    {
        return true;
    }

    // Reglas de FORMA: qué datos se esperan y cómo deben venir
    public function rules(): array
    {
        return [
 
            'materias' => ['required', 'array'],
            // El * aplica la regla a CADA elemento de la lista.
            // integer: debe ser un número entero. distinct: sin repetidos (no choca con la llave única).
            'materias.*' => ['integer', 'distinct'],

            'horarios' => ['required', 'array'],
            // "horarios.*.dia": en cada objeto de la lista, el campo "dia".
            // Rule::enum acepta solo los valores del enum Dia: "lunes", "martes"...
            // (rechaza "Lunes" con mayúscula, "sabado" o cualquier texto inventado)
            'horarios.*.dia' => ['required', Rule::enum(Dia::class)],
            // exists:horarios,id -> ese id debe existir en la tabla horarios
            'horarios.*.horario_id' => ['required', 'integer', 'exists:horarios,id'],

            'modalidades' => ['required', 'array'],
            'modalidades.*' => ['distinct', Rule::enum(Modalidad::class)],
        ];
    }

    // Validaciones extra que las reglas simples no pueden expresar.
    // Laravel las ejecuta DESPUÉS de rules().
    public function after(): array
    {
        return [function (Validator $validator) {
            // Si las reglas de arriba ya encontraron errores, no seguimos:
            // los datos podrían venir incompletos y este código daría errores raros.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // 1. Todas las materias deben existir y ser de MI facultad.
            //    whereHas('licenciatura') aplica el filtro por facultad (igual que MateriaController):
            //    una materia de otra facultad se rechaza como si no existiera.
            //    Una sola consulta cuenta cuántas son válidas y se compara con cuántas llegaron.
            $ids = $this->input('materias');

            if (Materia::whereHas('licenciatura')->whereIn('id', $ids)->count() !== count($ids)) {
                $validator->errors()->add('materias', 'Alguna de las materias no existe.');
            }

            // 2. No repetir la misma pareja día + horario.
            //    "distinct" no sirve aquí: marcaría "lunes" como repetido aunque tenga horarios distintos.
            //    Se arma un texto "lunes|3" por pareja y se comparan los totales.
            $pares = collect($this->input('horarios'))
                ->map(fn ($h) => $h['dia'].'|'.$h['horario_id']);

            if ($pares->count() !== $pares->unique()->count()) {
                $validator->errors()->add('horarios', 'No repitas el mismo día y horario.');
            }
        }];
    }

    // Mensajes en español que el front imprime tal cual.
    // La clave es "campo.regla"; sin esto Laravel los da en inglés.
    public function messages(): array
    {
        return [
            'materias.required' => 'Elige al menos una materia.',
            'horarios.required' => 'Elige al menos un horario.',
            'modalidades.required' => 'Elige al menos una modalidad.',
            'materias.*.distinct' => 'No repitas materias.',
            'modalidades.*.distinct' => 'No repitas modalidades.',
            'horarios.*.dia.enum' => 'El día no es válido.',
            'horarios.*.horario_id.exists' => 'El horario no existe.',
            'modalidades.*.enum' => 'La modalidad no es válida.',
        ];
    }
}