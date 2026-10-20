import { useEffect, useId, useRef, useState } from 'react';
import { ROLES } from '../../../constants/roles';
import { useAuth } from '../../../hooks/useAuth';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import AccountFailure from './AccountFailure';
import { useCredentialReceipt } from './CredentialReceiptHost';
import { useAccountSubmission } from '../hooks/useAccountSubmission';
import { cambiarEstadoCuenta, cambiarRolCuenta, emitirCredencialTemporal, esIdentificador } from '../services/usuario.service';

export default function AccountActions({ account, onChanged, onBusyChange, disabled }) {
  const { usuario, mutateAccount } = useAuth();
  const showReceipt = useCredentialReceipt();
  const [action, setAction] = useState(null);
  const [rol, setRol] = useState('');
  const [medicalId, setMedicalId] = useState(account.id_medico || '');
  const [identity, setIdentity] = useState(false);
  const form = useRef(null);
  const title = useRef(null);
  const buttons = useRef(null);
  const { busy, failure, remaining, execute, report } = useAccountSubmission(form);
  const patient = account.rol === ROLES.PACIENTE;
  const own = usuario?.id_usuario === account.id_usuario;
  const mayChangeState = ['Activa', 'Desactivada'].includes(account.estado_cuenta) && Boolean(account.correo_verificado_en);
  const mayIssue = !patient && account.estado_cuenta === 'Activa' && Boolean(account.correo_verificado_en);
  const destination = account.estado_cuenta === 'Activa' ? 'Desactivada' : 'Activa';
  const stateVerb = destination === 'Activa' ? 'Reactivar acceso' : 'Desactivar acceso';
  useEffect(() => { if (action) title.current?.focus(); }, [action]);
  useEffect(() => { onBusyChange(busy); return () => onBusyChange(false); }, [busy, onBusyChange]);

  function start(next) { setAction(next); setIdentity(false); setRol(''); report(null); }

  async function submit(event) {
    event.preventDefault();
    if (disabled) return;
    if (action === 'role' && rol === ROLES.MEDICO && !esIdentificador(medicalId)) {
      report({ message: 'Revisa el identificador profesional.', fields: { id_medico: 'Introduce un identificador entero positivo válido.' } }); return;
    }
    if ((action === 'temporary' || (action === 'role' && rol === ROLES.MEDICO && !account.id_medico)) && !identity) {
      report({ message: 'Debes comprobar presencialmente la identidad antes de continuar.', fields: { identidad_verificada: 'Confirma la comprobación presencial de identidad.' } }); return;
    }
    await execute(async () => {
      const request = action === 'state' ? () => cambiarEstadoCuenta(account.id_usuario, destination)
        : action === 'role' ? () => cambiarRolCuenta(account.id_usuario, { rol, id_medico: medicalId, identidad_verificada: identity })
          : () => emitirCredencialTemporal(account.id_usuario, identity);
      await mutateAccount(request, result => {
        if (action === 'temporary') showReceipt(result);
        else if (!own) onChanged(action === 'state' ? 'Estado de acceso actualizado.' : 'Rol actualizado. El trabajador deberá iniciar sesión de nuevo.');
      });
    });
  }

  if (!mayChangeState && patient) return null;
  return <section className="accounts-panel account-actions-panel">
    <h2>Administrar acceso</h2>
    <p>Los cambios conservan la identidad y el historial. El servidor vuelve a comprobar el estado y los permisos al guardar.</p>
    {!action ? <div ref={buttons} className="accounts-actions">
      {mayChangeState && <Button variant={destination === 'Desactivada' ? 'danger' : 'secondary'} disabled={disabled} onClick={() => start('state')}>{stateVerb}</Button>}
      {!patient && <Button variant="secondary" disabled={disabled} onClick={() => start('role')}>Cambiar rol</Button>}
      {mayIssue && <Button variant="secondary" disabled={disabled} onClick={() => start('temporary')}>Emitir credencial temporal</Button>}
    </div> : <form ref={form} aria-label="Modificar acceso de cuenta" aria-busy={busy} onSubmit={submit}>
      <h3 ref={title} tabIndex="-1">{action === 'state' ? stateVerb : action === 'role' ? 'Cambiar rol del personal' : 'Emitir credencial temporal'}</h3>
      <AccountFailure failure={failure} remaining={remaining} />
      {own && <p className="account-action-note">Estás modificando tu propia cuenta. Si se confirma el cambio, se cerrará tu sesión actual. Debe quedar otro Administrador habilitado.</p>}
      {action === 'state' ? <p className="account-action-note">{destination === 'Desactivada'
        ? `¿Desactivar el acceso de ${account.correo_acceso}? Se cerrarán todas sus sesiones.`
        : `¿Reactivar el acceso de ${account.correo_acceso}? Tendrá que iniciar sesión de nuevo. No se activa su ficha ni se eliminan bloqueos temporales.`}</p>
        : action === 'role' ? <>
          <SelectField label="Nuevo rol del personal" required value={rol} disabled={busy} onChange={event => { setRol(event.target.value); setIdentity(false); }} error={failure?.fields.rol}>
            <option value="">Selecciona un rol</option>{[ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA, ROLES.MEDICO].filter(value => value !== account.rol).map(value => <option key={value}>{value}</option>)}
          </SelectField>
          <p className="account-action-note">El cambio revoca todas las sesiones y exige nuevo login. No crea horarios ni modifica la actividad profesional.</p>
          {account.rol === ROLES.MEDICO && <p className="account-action-note">Retirar el rol Médico requiere inactividad profesional expresa y ausencia comprobable de obligaciones asistenciales o documentación pendiente. Se conserva el vínculo histórico.</p>}
          {rol === ROLES.MEDICO && <><FormField label="Identificador del registro médico" type="text" inputMode="numeric" required maxLength={19}
            readOnly={busy || Boolean(account.id_medico)} value={medicalId} onChange={event => setMedicalId(event.target.value)} error={failure?.fields.id_medico}
            hint={account.id_medico ? 'Se conserva el mismo vínculo profesional histórico.' : 'Registro profesional de la misma persona, existente y disponible.'} />
            {!account.id_medico && <IdentityConfirmation checked={identity} onChange={setIdentity} disabled={busy} error={failure?.fields.identidad_verificada} />}</>}
        </> : <>
          <p className="account-action-note">Solo para entrega presencial al trabajador. La credencial se mostrará una vez y vence en 30 minutos. Invalida las sesiones, temporales y recuperaciones anteriores y exige establecer una contraseña definitiva. No elimina bloqueos vigentes.</p>
          <IdentityConfirmation checked={identity} onChange={setIdentity} disabled={busy} error={failure?.fields.identidad_verificada} />
        </>}
      <div className="accounts-actions"><Button type="submit" loading={busy} disabled={disabled || remaining > 0}
        variant={action === 'state' && destination === 'Desactivada' ? 'danger' : 'primary'}>
        {busy ? 'Guardando cambio…' : remaining > 0 ? `Espera ${remaining} s` : action === 'state' ? `Confirmar: ${stateVerb.toLowerCase()}` : action === 'role' ? 'Confirmar cambio de rol' : 'Confirmar emisión'}</Button>
        <Button variant="quiet" disabled={busy} onClick={() => { setAction(null); requestAnimationFrame(() => buttons.current?.querySelector('button')?.focus()); }}>Volver</Button></div>
      {failure && <p className="accounts-footnote">Puedes actualizar el detalle para comprobar el estado actual. No se reenvía el cambio automáticamente.</p>}
    </form>}
  </section>;
}

function IdentityConfirmation({ checked, onChange, disabled, error }) {
  const id = useId();
  return <div className="identity-confirmation"><label><input type="checkbox" checked={checked} required disabled={disabled}
    aria-invalid={Boolean(error)} aria-describedby={error ? id : undefined} onChange={event => onChange(event.target.checked)} />He comprobado presencialmente la identidad del trabajador y su correspondencia con esta cuenta y, si aplica, con el registro médico.</label>
    {error && <p className="field-error" id={id}>{error}</p>}</div>;
}
