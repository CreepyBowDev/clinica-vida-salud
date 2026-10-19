<?php

namespace Clinica\presentation\dtos;

class AceptarInvitacionRequest extends ClaveRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ['token.regex' => 'La invitación no es válida.']);
    }
}
