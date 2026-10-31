<?php

namespace Clinica\presentation\dtos\recordatorios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SuscripcionPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [...$this->all(), 'id_objetivo' => $this->route('id')];
    }

    public function rules(): array
    {
        $r = ['id_objetivo' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'regex:/\A[1-9][0-9]{0,18}\z/']];
        if ($this->isMethod('PUT')) {
            return [...$r, 'permiso_otorgado' => ['required', function ($k, $v, $fail): void {
                if ($v !== true) {
                    $fail('Autoriza explícitamente las notificaciones de este dispositivo.');
                }
            }], 'suscripcion' => ['required', 'array:endpoint,expirationTime,keys'],
                'suscripcion.endpoint' => ['required', 'string', 'max:2048'],
                'suscripcion.expirationTime' => ['present', 'nullable', 'integer', 'min:1', 'max:8640000000000000'],
                'suscripcion.keys' => ['required', 'array:p256dh,auth'],
                'suscripcion.keys.p256dh' => ['required', 'string', 'size:87'],
                'suscripcion.keys.auth' => ['required', 'string', 'size:22']];
        }

        return [...$r, 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function (Validator $v): void {
            $put = $this->isMethod('PUT');
            $list = $this->routeIs('push.lista');
            $allowed = $put ? ['permiso_otorgado', 'suscripcion'] : ($list ? ['page', 'per_page'] : []);
            if ($put && (! $this->isJson() || $this->query->all() !== [])) {
                $v->errors()->add('body', 'Esta operación requiere JSON sin parámetros de consulta.');
            }
            if (! $put && $this->getContent() !== '' && $this->getContent() !== '[]') {
                $v->errors()->add('body', 'Esta operación no admite cuerpo.');
            }
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $v->errors()->add($key, 'Este campo no está permitido.');
            }
        });
    }
}
