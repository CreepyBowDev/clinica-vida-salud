import { api, csrfURL } from '../../../api/api';

export async function registrarPaciente(input) {
  const fields = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'telefono', 'sexo', 'direccion', 'correo_contacto', 'correo_acceso', 'password', 'password_confirmation'];
  const data = Object.fromEntries(fields.filter(key => input[key] !== undefined).map(key => [key, input[key]]));
  await api.get(csrfURL);
  await api.post('/auth/registro', data);
}

export async function vincularPaciente({ documento_identidad, codigo_activacion, correo_acceso, password, password_confirmation }) {
  await api.get(csrfURL);
  await api.post('/auth/registro/vincular', { documento_identidad, codigo_activacion, correo_acceso, password, password_confirmation });
}

export async function reenviarCodigoRegistro() {
  await api.get(csrfURL);
  await api.post('/auth/registro/reenviar');
}

export async function verificarRegistro({ codigo }) {
  await api.get(csrfURL);
  await api.post('/auth/registro/verificar', { codigo });
}
