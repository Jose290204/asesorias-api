<?php

namespace App\Http\Requests;

class ListarSolicitudesAdminRequest extends ListarSolicitudesRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'asesor_id' => ['nullable', 'integer'],
            'estudiante_id' => ['nullable', 'integer'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ];
    }
}