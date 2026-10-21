import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Button from '../../../components/Button';
import { ROLES } from '../../../constants/roles';
import { crearPersonal, esIdentificador } from '../services/usuario.service';
import { useAccountSubmission } from '../hooks/useAccountSubmission';
import AccountFailure from '../components/AccountFailure';
import InvitationResult from '../components/InvitationResult';
import '../styles/accounts.css';

export default function NewStaffPage() {
  const [draft, setDraft] = useState({ rol: ROLES.RECEPCIONISTA, correo_acceso: '', nombres_personal: '', apellidos_personal: '', id_medico: '' });
  const [result, setResult] = useState(null);
  const form = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, execute, report } = useAccountSubmission(form);
  useEffect(() => { if (result) heading.current?.focus(); }, [result]);
  const medical = draft.rol === ROLES.MEDICO;
  const field = name => ({ name, value: draft[name], readOnly: busy, onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: failure?.fields[name] });

  return <section className="workspace-page accounts-page staff-create-page">
    <Link className="accounts-back" to="/administracion/cuentas">Volver a cuentas</Link>
    <header className="page-heading"><h1 ref={heading} tabIndex="-1">{result ? 'Cuenta del personal creada' : 'Crear cuenta del personal'}</h1>
      <p>{result ? 'La cuenta permanece pendiente hasta que el trabajador acepte su invitación.' : 'El trabajador verificará su correo y elegirá su propia contraseña.'}</p></header>
    {result ? <div className="accounts-panel"><p className="accounts-created-email">{result.data.correo_acceso}</p><InvitationResult result={result} />
      <Link className="button button--primary" to={`/administracion/cuentas/${result.data.id_usuario}`}>Ver cuenta creada</Link></div>
      : <div className="security-panel"><div className="security-description"><h2>Acceso para el equipo</h2><p>Asigna uno de los tres roles del personal. La invitación permite establecer la primera contraseña y verificar el correo.</p>
        <p>Para un Médico necesitas el identificador de su registro profesional existente. El sistema comprueba que esté disponible y conserva sus nombres y datos profesionales.</p></div>
        <form ref={form} className="security-form" aria-label="Crear cuenta del personal" aria-busy={busy} onSubmit={async event => {
          event.preventDefault();
          if (medical && !esIdentificador(draft.id_medico)) { report({ message: 'Revisa el identificador profesional.', fields: { id_medico: 'Introduce un identificador entero positivo válido.' } }); return; }
          await execute(async () => { const response = await crearPersonal({ ...draft, correo_acceso: draft.correo_acceso.trim() }); setResult(response); });
        }}>
          <AccountFailure failure={failure} remaining={remaining} />
          {failure && !failure.status && <p className="accounts-footnote">Consulta el correo exacto en el listado antes de repetir el alta: la cuenta podría haberse creado.</p>}
          <SelectField label="Rol del personal" value={draft.rol} disabled={busy} error={failure?.fields.rol} onChange={event => setDraft({ ...draft, rol: event.target.value, nombres_personal: '', apellidos_personal: '', id_medico: '' })}>
            {[ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA, ROLES.MEDICO].map(rol => <option key={rol}>{rol}</option>)}</SelectField>
          <FormField label="Correo de acceso" type="email" autoComplete="off" required maxLength={254} {...field('correo_acceso')} />
          {medical ? <FormField label="Identificador del registro médico" type="text" inputMode="numeric" required maxLength={19} hint="Registro profesional ya existente, sin cuenta vinculada. No crea un nuevo Médico." {...field('id_medico')} />
            : <div className="staff-name-fields"><FormField label="Nombres" autoComplete="off" required maxLength={120} {...field('nombres_personal')} /><FormField label="Apellidos" autoComplete="off" required maxLength={120} {...field('apellidos_personal')} /></div>}
          <Button type="submit" loading={busy} disabled={remaining > 0}>{busy ? 'Creando cuenta…' : remaining > 0 ? `Espera ${remaining} s` : 'Crear cuenta e invitar'}</Button>
        </form></div>}
  </section>;
}
