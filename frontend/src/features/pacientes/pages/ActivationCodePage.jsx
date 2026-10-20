import { useEffect, useRef, useState } from 'react';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { emitirCodigoActivacion } from '../services/activation.service';
import { useRegistrationSubmission } from '../../auth/hooks/useRegistrationSubmission';
import PasswordFailure from '../../auth/components/PasswordFailure';
import '../../usuarios/styles/accounts.css';
import '../styles/activation.css';

export default function ActivationCodePage() {
  const [id, setId] = useState('');
  const [identity, setIdentity] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [receipt, setReceipt] = useState(null);
  const form = useRef(null);
  const heading = useRef(null);
  const { busy, failure, remaining, execute, report } = useRegistrationSubmission(form);
  useEffect(() => { if (receipt) heading.current?.focus(); }, [receipt]);
  useEffect(() => { const clear = () => setReceipt(null); window.addEventListener('pagehide', clear); return () => window.removeEventListener('pagehide', clear); }, []);
  return <section className="workspace-page accounts-page activation-page">
    <header className="page-heading"><h1 ref={heading} tabIndex="-1">{receipt ? 'Código de activación emitido' : 'Activación web de Paciente'}</h1><p>Autorización presencial para vincular la ficha existente al portal.</p></header>
    {receipt ? <div className="accounts-panel"><h2>Entrega presencial única</h2><p>Ficha {receipt.id_paciente}. Comunica el código a la persona cuya identidad verificaste. Al ocultarlo no podrá volver a consultarse.</p>
      <output className="credential-secret" aria-label="Código de activación para entrega presencial">{receipt.codigo_activacion}</output>
      <p>Vence el <time dateTime={receipt.expira_en}>{new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(receipt.expira_en))} UTC</time>. Un solo uso, cinco intentos.</p>
      <p>El Paciente debe abrir «Ya tengo un código de activación», introducir su CI, este código, correo y contraseña, y verificar después el segundo código de seis dígitos de su correo. Esta emisión no crea una cuenta ni activa la ficha.</p>
      <Button onClick={() => { setReceipt(null); setId(''); setIdentity(false); setConfirm(false); }}>Ya comuniqué el código; ocultar</Button>
    </div> : <form ref={form} className="accounts-panel" aria-label="Emitir código de activación" aria-busy={busy} onSubmit={event => {
      event.preventDefault();
      if (!esIdentificador(id)) { report({ message: 'Revisa la ficha seleccionada.', fields: { id_paciente: 'Introduce un identificador entero positivo válido.' } }); return; }
      if (!identity) { report({ message: 'Comprueba presencialmente la identidad y el CI antes de continuar.', fields: {} }); return; }
      if (!confirm) { setConfirm(true); return; }
      execute(async () => {
        const data = await emitirCodigoActivacion(id, true);
        if (data?.id_paciente !== id || !/^[A-Za-z0-9]{12}$/.test(data?.codigo_activacion) || !Number.isFinite(Date.parse(data?.expira_en))) throw new Error('Entrega no confirmada.');
        setReceipt({ id_paciente: data.id_paciente, codigo_activacion: data.codigo_activacion, expira_en: data.expira_en });
      });
    }}>
      <PasswordFailure failure={failure} remaining={remaining} />
      <p>Busca la ficha en el registro administrativo y comprueba presencialmente su identidad y CI. Todavía no hay un catálogo de pacientes disponible en este portal.</p>
      <FormField name="id_paciente" label="Identificador de la ficha" type="text" inputMode="numeric" required maxLength={19} value={id} readOnly={busy || confirm} error={failure?.fields.id_paciente} onChange={event => { setId(event.target.value); setIdentity(false); }} hint="Identificador interno de la ficha comprobada; no es el CI." />
      <div className="identity-confirmation"><label><input type="checkbox" required checked={identity} disabled={busy || confirm} onChange={event => setIdentity(event.target.checked)} />He comprobado presencialmente la identidad, la correspondencia del CI con esta ficha y que el Paciente no tiene cuenta web vinculada.</label></div>
      {confirm && <p role="status">¿Emitir un código para la ficha {id}? Se invalidarán el código anterior y los procesos de vinculación asociados. Se mostrará una vez para entrega presencial.</p>}
      <div className="accounts-actions"><Button type="submit" loading={busy} disabled={remaining > 0}>{busy ? 'Emitiendo código…' : remaining > 0 ? `Espera ${remaining} s` : confirm ? 'Confirmar emisión presencial' : 'Revisar emisión'}</Button>{confirm && <Button variant="quiet" disabled={busy} onClick={() => setConfirm(false)}>Volver a revisar ficha</Button>}</div>
      <p className="accounts-footnote">Una ficha sin CI necesita incorporación del documento a la misma ficha por el procedimiento administrativo. Un fallo de red deja el resultado incierto: no se repite la emisión automáticamente.</p>
    </form>}
  </section>;
}
