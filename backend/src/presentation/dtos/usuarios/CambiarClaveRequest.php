<?php

namespace Clinica\presentation\dtos\usuarios;

class CambiarClaveRequest extends ClaveRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'current_password' => ['required', 'string', 'max:1024'],
        ]);
    }
}
