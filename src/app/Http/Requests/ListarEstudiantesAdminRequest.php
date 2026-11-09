<?php

namespace App\Http\Requests;

// GET /admin/estudiantes: la búsqueda de siempre más los filtros del panel del admin
class ListarEstudiantesAdminRequest extends ListarEstudiantesRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'licenciatura_id' => ['nullable', 'integer'],
            'grupo_id' => ['nullable', 'integer'],
        ];
    }
}