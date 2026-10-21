<?php

namespace Clinica\presentation\dtos\usuarios;

class RestablecerClaveRequest extends ClaveRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'token.regex' => 'El enlace no es válido.'];
    }
}
