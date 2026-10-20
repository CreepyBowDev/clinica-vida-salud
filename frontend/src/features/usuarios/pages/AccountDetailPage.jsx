import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import Button from '../../../components/Button';
import { ROLES } from '../../../constants/roles';
import { useAccountQuery } from '../hooks/useAccountQuery';
import { useAccountSubmission } from '../hooks/useAccountSubmission';
import { esIdentificador, reenviarInvitacion } from '../services/usuario.service';
import AccountFailure from '../components/AccountFailure';
import InvitationResult from '../components/InvitationResult';
import AccountActions from '../components/AccountActions';
import Alert from '../../../components/Alert';
import '../styles/accounts.css';

export default function AccountDetailPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Cuenta no disponible</h1><Link to="/administracion/cuentas">Volver a cuentas</Link></section>;
  return <AccountDetail key={id} id={id} />;
}

function AccountDetail({ id }) {
  const { data: account, failure, loading, reload } = useAccountQuery('detail', id);
  const [result, setResult] = useState(null);
  const [confirm, setConfirm] = useState(false);
  const [notice, setNotice] = useState('');
  const [actionBusy, setActionBusy] = useState(false);
  const form = useRef(null);
  const resultNotice = useRef(null);
  const submission = useAccountSubmission(form);
  useEffect(() => {
    if (confirm) form.current?.querySelector('button[type="submit"]')?.focus();
  }, [confirm]);
  useEffect(() => { if (result && !loading) resultNotice.current?.focus(); }, [result, loading]);
  const eligible = account && account.rol !== ROLES.PACIENTE && account.estado_cuenta === 'Pendiente' && !account.correo_verificado_en;
  return <section className="workspace-page accounts-page">
    <Link className="accounts-back" to="/administracion/cuentas">Volver a cuentas</Link>
    <header className="page-heading"><h1>Detalle de cuenta</h1><p>Información administrativa del acceso al portal.</p></header>
    {notice && <Alert variant="success">{notice}</Alert>}
    {loading ? <p role="status">Consultando cuenta…</p> : failure ? <><AccountFailure failure={failure} /><Button variant="secondary" onClick={reload}>Volver a consultar</Button></>
      : <><dl className="account-summary accounts-panel">
        <div><dt>Identificador de cuenta</dt><dd>{account.id_usuario}</dd></div>
        {account.rol !== ROLES.PACIENTE && <div><dt>Nombre</dt><dd>{account.nombre_visible}</dd></div>}
        <div><dt>Correo de acceso</dt><dd>{account.correo_acceso}</dd></div><div><dt>Rol</dt><dd>{account.rol}</dd></div>
        <div><dt>Estado de cuenta</dt><dd>{account.estado_cuenta}</dd></div>
        <div><dt>Verificación de correo</dt><dd>{account.correo_verificado_en ? 'Verificado' : 'Pendiente de verificación'}</dd></div>
        {account.rol !== ROLES.PACIENTE && account.id_medico && <div><dt>Registro médico vinculado</dt><dd>{account.id_medico}</dd></div>}
      </dl>
        {account.rol === ROLES.PACIENTE ? <p className="accounts-footnote">Esta consulta muestra solo datos mínimos de acceso. La cuenta, la ficha del paciente y los bloqueos temporales son independientes.</p>
          : eligible && <section className="accounts-panel invitation-panel"><h2>Invitación inicial</h2><p>La cuenta está pendiente de establecer su primera contraseña. Un nuevo envío invalida el enlace anterior.</p>
            <div ref={resultNotice} tabIndex={result ? -1 : undefined}><InvitationResult result={result} /></div>
            <form ref={form} aria-label="Reenviar invitación" onSubmit={async event => {
              event.preventDefault();
              if (actionBusy) return;
              await submission.execute(async () => { const response = await reenviarInvitacion(id); setResult(response); setConfirm(false); reload(); });
            }}>
              <AccountFailure failure={submission.failure} remaining={submission.remaining} />
              {confirm ? <div className="invitation-confirm"><p>¿Emitir una nueva invitación para {account.correo_acceso}? El enlace anterior dejará de ser válido.</p>
                <div className="accounts-actions"><Button type="submit" loading={submission.busy} disabled={actionBusy || submission.remaining > 0}>{submission.busy ? 'Solicitando envío…' : submission.remaining > 0 ? `Espera ${submission.remaining} s` : 'Confirmar nuevo envío'}</Button>
                  <Button variant="quiet" disabled={submission.busy || actionBusy} onClick={() => { setConfirm(false); requestAnimationFrame(() => form.current?.querySelector('button')?.focus()); }}>Volver</Button></div></div>
                : <Button variant="secondary" disabled={actionBusy || submission.remaining > 0} onClick={() => setConfirm(true)}>Reenviar invitación</Button>}
            </form>
          </section>}
        <AccountActions account={account} disabled={submission.busy} onBusyChange={setActionBusy} onChanged={message => { setNotice(message); setResult(null); reload(); }} />
        <div className="accounts-actions"><Button variant="secondary" disabled={submission.busy || actionBusy} onClick={() => { setNotice(''); reload(); }}>Actualizar detalle</Button></div>
      </>}
  </section>;
}
