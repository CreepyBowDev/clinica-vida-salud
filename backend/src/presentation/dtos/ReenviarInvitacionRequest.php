<?php

namespace Clinica\presentation\dtos;

use Illuminate\Foundation\Http\FormRequest;

class ReenviarInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return ['id_usuario' => $this->route('id')];
    }

    public function rules(): array
    {
        return ['id_usuario' => ['required', 'integer', 'min:1', 'max:9223372036854775807']];
    }

    public function messages(): array
    {
        return ['id_usuario.*' => 'El identificador de la cuenta no es válido.'];
    }
}
