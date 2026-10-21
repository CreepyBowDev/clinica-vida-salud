import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import Button from '../../../components/Button';
import { ROLES } from '../../../constants/roles';
import { crearPersonal, esIdentificador } from '../services/usuario.service';
import { useAccountSubmission } from '../hooks/useAccountSubmission';
import AccountFailure from '../components/AccountFailure';
import InvitationResult from '../components/InvitationResult';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { consultarMedico } from '../../medicos/services/medico.service';
import DoctorFailure from '../../medicos/components/DoctorFailure';
import '../styles/accounts.css';

const queryFields = [];
export default function NewStaffPage() {
  const [params] = useSearchParams();
  if (!params.has('id_medico')) return <StaffForm key="general" />;
  const id = params.get('id_medico');
  if (!esIdentificador(id) || params.getAll('id_medico').length !== 1) return <section className="page-message"><h1>Registro médico no disponible</h1><Link to="/administracion/medicos">Consultar médicos</Link></section>;
  return <SelectedDoctor key={id} id={id} />;
}
function SelectedDoctor({ id }) {
  const query = useCatalogQuery(consultarMedico, id, queryFields);
  if (query.loading) return <p role="status">Consultando registro médico…</p>;
  if (query.failure) return <section className="workspace-page"><h1>Crear cuenta del personal</h1><DoctorFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button><Link to="/administracion/medicos">Consultar médicos</Link></section>;
  if (query.data.cuenta_vinculada) return <section className="page-message"><h1>El médico ya tiene una cuenta vinculada</h1><p>Consulta su registro y administra el acceso desde cuentas de usuario.</p><Link to={`/administracion/medicos/${id}`}>Volver al médico</Link></section>;
  return <StaffForm doctor={query.data} />;
}
function StaffForm({ doctor }) {
  const [draft, setDraft] = useState({ rol: doctor ? ROLES.MEDICO : ROLES.RECEPCIONISTA, correo_acceso: '', nombres_personal: '', apellidos_personal: '', id_medico: doctor?.id_medico || '' });
  const [result, setResult] = useState(null);
  const form = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, execute, report } = useAccountSubmission(form);
  useEffect(() => { if (result) heading.current?.focus(); }, [result]);
  const medical = draft.rol === ROLES.MEDICO;
  const uncertain = Boolean(doctor && failure && (!failure.status || failure.status >= 500));
  const field = name => ({ name, value: draft[name], readOnly: busy, onChange: event => setDraft({ ...draft, [name]: event.target.value }), error: failure?.fields[name] });

  return <section className="workspace-page accounts-page staff-create-page">
    <div className="accounts-backlinks"><Link className="accounts-back" to="/administracion/cuentas">Volver a cuentas</Link>
      {doctor && <Link className="accounts-back" to={`/administracion/medicos/${doctor.id_medico}`}>Volver al médico</Link>}</div>
    <header className="page-heading"><h1 ref={heading} tabIndex="-1">{result ? 'Cuenta del personal creada' : 'Crear cuenta del personal'}</h1>
      <p>{result ? 'La cuenta permanece pendiente hasta que el trabajador acepte su invitación.' : 'El trabajador verificará su correo y elegirá su propia contraseña.'}</p></header>
    {result ? <div className="accounts-panel"><p className="accounts-created-email">{result.data.correo_acceso}</p><InvitationResult result={result} />
      <Link className="button button--primary" to={`/administracion/cuentas/${result.data.id_usuario}`}>Ver cuenta creada</Link></div>
      : <div className="security-panel"><div className="security-description"><h2>Acceso para el equipo</h2><p>Asigna uno de los tres roles del personal. La invitación permite establecer la primera contraseña y verificar el correo.</p>
        <p>Para un Médico necesitas el identificador de su registro profesional existente. El sistema comprueba que esté disponible y conserva sus nombres y datos profesionales.</p>
        {doctor && <p><strong>{doctor.nombres} {doctor.apellidos}</strong> · {doctor.codigo_medico}. Registro seleccionado; el rol y el vínculo están fijados para esta alta. Crear la cuenta no cambia su actividad profesional.</p>}</div>
        <form ref={form} className="security-form" aria-label="Crear cuenta del personal" aria-busy={busy} onSubmit={async event => {
          event.preventDefault();
          if (busy || uncertain || remaining > 0) return;
          if (medical && !esIdentificador(draft.id_medico)) { report({ message: 'Revisa el identificador profesional.', fields: { id_medico: 'Introduce un identificador entero positivo válido.' } }); return; }
          await execute(async () => { const response = await crearPersonal({ ...draft, correo_acceso: draft.correo_acceso.trim() }); setResult(response); });
        }}>
          <AccountFailure failure={failure} remaining={remaining} />
          {failure && !failure.status && <p className="accounts-footnote">Consulta el correo exacto en el listado antes de repetir el alta: la cuenta podría haberse creado.</p>}
          {uncertain && <p className="accounts-footnote">No se confirmó el resultado. Vuelve al médico y consulta el vínculo vigente antes de iniciar otra alta.</p>}
          <SelectField label="Rol del personal" value={draft.rol} disabled={busy || Boolean(doctor)} error={failure?.fields.rol} onChange={event => setDraft({ ...draft, rol: event.target.value, nombres_personal: '', apellidos_personal: '', id_medico: '' })}>
            {[ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA, ROLES.MEDICO].map(rol => <option key={rol}>{rol}</option>)}</SelectField>
          <FormField label="Correo de acceso" type="email" autoComplete="off" required maxLength={254} {...field('correo_acceso')} />
          {medical ? <FormField label="Identificador del registro médico" type="text" inputMode="numeric" required maxLength={19} hint="Registro profesional ya existente, sin cuenta vinculada. No crea un nuevo Médico." {...field('id_medico')} readOnly={busy || Boolean(doctor)} />
            : <div className="staff-name-fields"><FormField label="Nombres" autoComplete="off" required maxLength={120} {...field('nombres_personal')} /><FormField label="Apellidos" autoComplete="off" required maxLength={120} {...field('apellidos_personal')} /></div>}
          <Button type="submit" loading={busy} disabled={uncertain || remaining > 0}>{busy ? 'Creando cuenta…' : remaining > 0 ? `Espera ${remaining} s` : 'Crear cuenta e invitar'}</Button>
        </form></div>}
  </section>;
}
