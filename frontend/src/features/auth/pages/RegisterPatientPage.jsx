import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import FormField from '../../../components/FormField';
import PasswordField from '../../../components/PasswordField';
import Button from '../../../components/Button';
import PasswordFailure from '../components/PasswordFailure';
import RegistrationSession from '../components/RegistrationSession';
import { useRegistrationSubmission } from '../hooks/useRegistrationSubmission';
import { registrarPaciente, vincularPaciente } from '../services/registration.service';
import { validarNuevaClave } from '../utils/passwordValidation';
import { useAuth } from '../../../hooks/useAuth';
import '../styles/registration.css';

export default function RegisterPatientPage({ linking = false }) {
  return <RegistrationSession><RegistrationForm key={String(linking)} linking={linking} /></RegistrationSession>;
}

function RegistrationForm({ linking }) {
  const [values, setValues] = useState({ documento_identidad: '', nombres: '', apellidos: '', fecha_nacimiento: '', telefono: '', sexo: '', direccion: '', correo_contacto: '', correo_acceso: '', codigo_activacion: '', password: '', password_confirmation: '' });
  const form = useRef(null);
  const active = useRef(false);
  const navigate = useNavigate();
  const { refreshSession } = useAuth();
  const { busy, failure, remaining, execute, report } = useRegistrationSubmission(form);
  useEffect(() => {
    active.current = true;
    document.title = `${linking ? 'Vincular mi ficha' : 'Crear cuenta de Paciente'} | Clínica Vida Salud`;
    return () => { active.current = false; };
  }, [linking]);
  const change = event => setValues(previous => ({ ...previous, [event.target.name]: event.target.value }));
  const input = (name, label, props = {}) => <FormField name={name} label={label} value={values[name]} onChange={change} readOnly={busy} error={failure?.fields[name]} {...props} />;
  async function submit(event) {
    event.preventDefault();
    const fields = validarNuevaClave(values.password, values.password_confirmation);
    const ci = values.documento_identidad.replace(/^ +| +$/g, '');
    if (!ci || ci.length > 50 || /\p{C}/u.test(ci)) fields.documento_identidad = 'El CI es obligatorio para el registro web y no debe contener caracteres de control. Acude a Recepción si necesitas asistencia.';
    if (linking && !/^[A-Za-z0-9]{12}$/.test(values.codigo_activacion)) fields.codigo_activacion = 'Introduce los 12 caracteres alfanuméricos del código de Recepción.';
    if (Object.keys(fields).length) { report({ message: 'Revisa los campos indicados.', fields }); return; }
    await execute(async () => {
      try {
        await (linking ? vincularPaciente(values) : registrarPaciente(values));
        if (active.current) navigate('/verificar-registro', { replace: true, state: { solicitudRecibida: true } });
      } catch (error) {
        if (error.response?.data?.error?.code === 'AUTH_SESION_EXISTENTE') await refreshSession();
        throw error;
      } finally { setValues(previous => ({ ...previous, password: '', password_confirmation: '', codigo_activacion: '' })); }
    });
  }
  return <div className="login-panel registration-panel">
    <h1>{linking ? 'Vincula tu ficha al portal' : 'Crea tu cuenta de Paciente'}</h1>
    <p className="login-intro">{linking ? 'Usa la autorización entregada presencialmente por Recepción. Se conservarán tu ficha y tu historial.' : 'Si nunca te registraste en la clínica, comienza aquí. Verificarás tu correo antes de ingresar.'}</p>
    <Link className="registration-switch" to={linking ? '/registro' : '/vincular-ficha'}>{linking ? 'Soy Paciente nuevo' : 'Ya tengo un código de activación'}</Link>
    <form ref={form} className="recovery-form" aria-label={linking ? 'Vincular ficha de Paciente' : 'Registrar Paciente nuevo'} aria-busy={busy} onSubmit={submit}>
      <PasswordFailure failure={failure} remaining={remaining} />
      {input('documento_identidad', 'CI', { required: true, maxLength: 50, hint: 'El CI es obligatorio para el registro web. Si no tienes uno, acude a Recepción para asistencia administrativa.' })}
      {linking ? input('codigo_activacion', 'Código de Recepción', { required: true, minLength: 12, maxLength: 12, autoComplete: 'off', spellCheck: false, hint: '12 caracteres alfanuméricos. Es de un solo uso y vence a las 24 horas de su emisión.' }) : <>
        <div className="registration-grid">{input('nombres', 'Nombres', { required: true, maxLength: 120, autoComplete: 'given-name' })}{input('apellidos', 'Apellidos', { required: true, maxLength: 120, autoComplete: 'family-name' })}</div>
        <div className="registration-grid">{input('fecha_nacimiento', 'Fecha de nacimiento', { required: true, type: 'date', autoComplete: 'bday' })}{input('telefono', 'Teléfono', { required: true, type: 'tel', maxLength: 40, autoComplete: 'tel' })}</div>
        {input('sexo', 'Sexo (opcional)', { maxLength: 32 })}
        {input('direccion', 'Dirección (opcional)', { maxLength: 2000, autoComplete: 'street-address' })}
        {input('correo_contacto', 'Correo de contacto (opcional)', { type: 'email', maxLength: 254, hint: 'Es distinto del correo de acceso. No se copia automáticamente.' })}
      </>}
      {input('correo_acceso', 'Correo de acceso', { required: true, type: 'email', maxLength: 254, autoComplete: 'username', hint: 'Usa un correo propio al que puedas acceder para verificarlo.' })}
      <PasswordField name="password" label="Contraseña" autoComplete="new-password" minLength={12} hint="Mínimo 12 caracteres. Puedes usar una frase." value={values.password} busy={busy} onChange={change} error={failure?.fields.password} />
      <PasswordField name="password_confirmation" label="Confirmar contraseña" autoComplete="new-password" value={values.password_confirmation} busy={busy} onChange={change} error={failure?.fields.password_confirmation} />
      <Button type="submit" className="full-width" loading={busy} disabled={remaining > 0}>{busy ? 'Enviando solicitud…' : remaining > 0 ? `Espera ${remaining} s` : 'Continuar a verificación de correo'}</Button>
    </form>
    <p className="recovery-note">{linking ? 'Después necesitarás un segundo código de seis dígitos enviado a tu correo. Si el código presencial venció o se agotó, solicita otro en Recepción.' : 'El proceso pendiente dura 24 horas. Solicitar otro código no amplía ese plazo.'}</p>
    <Link className="auth-back-link" to="/verificar-registro">Ya inicié una solicitud; verificar mi correo</Link>
    <Link className="auth-back-link" to="/login">Volver a iniciar sesión</Link>
  </div>;
}
