import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { describirError } from '../../../utils/httpError';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarMedico } from '../../medicos/services/medico.service';
import { consultarAsignacion, consultarImpactoAsignacion, confirmarAsignacion } from '../services/asignacionConsultorio.service';
import ConsultorioSelector from '../components/ConsultorioSelector';
import AsignacionImpacto from '../components/AsignacionImpacto';
import ConsultorioFailure from '../components/ConsultorioFailure';
import '../../../styles/catalogs.css';
import '../styles/asignacion.css';

const fields = [];
async function contexto(id, signal) {
  const [medico, asignacion] = await Promise.all([consultarMedico(id, signal), consultarAsignacion(id, signal)]);
  return { medico, asignacion };
}
const mensajes = {
  MEDICO_EDICION_OBSOLETA: 'El médico cambió. Consulta la asignación actual y revisa otra vez el impacto.',
  ASIGNACION_IMPACTO_OBSOLETO: 'El impacto cambió. Consulta otra vista previa y confirma de nuevo los traslados.',
  ASIGNACION_CONFIRMACION_REQUERIDA: 'Debes aceptar expresamente el conjunto completo de cambios de ubicación.',
  CONSULTORIO_NO_DISPONIBLE: 'El consultorio ya no está disponible. Revisa el destino y consulta otro impacto.',
  PLANIFICACION_CONFLICTO: 'La asignación tiene conflictos de horarios u ocupación. Consulta el impacto actualizado.',
  ATENCION_NO_TRASLADABLE: 'Hay una atención iniciada o documentada que no puede trasladarse. Revisa los conflictos; no cambies fechas ni estados de citas desde aquí.',
};
export default function AsignacionConsultorioPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Médico no disponible</h1><Link to="/administracion/medicos">Volver a médicos</Link></section>;
  return <Load key={id} id={id} />;
}
function Load({ id }) {
  const query = useCatalogQuery(contexto, id, fields);
  return <section className="workspace-page catalog-page"><Link to={`/administracion/medicos/${id}`}>Volver al médico</Link>
    <header className="page-heading"><h1>Asignar consultorio</h1><p>Revisa la ocupación y los cambios de ubicación antes de confirmar la asignación fija.</p></header>
    {query.loading ? <p role="status">Consultando médico y asignación…</p> : query.failure ? <><ConsultorioFailure failure={query.failure} remaining={query.remaining} /><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></>
      : <Assignment id={id} initial={query.data} />}
  </section>;
}
function Assignment({ id, initial }) {
  const [base, setBase] = useState(initial);
  const [destino, setDestino] = useState(null);
  const [impacto, setImpacto] = useState(null);
  const [aceptado, setAceptado] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState('');
  const [failure, setFailure] = useState(null);
  const [blocked, setBlocked] = useState(false);
  const [notice, setNotice] = useState('');
  const [until, setUntil] = useState(0);
  const [remaining, setRemaining] = useState(0);
  const request = useRef(null);
  const sequence = useRef(0);
  const locked = useRef(false);
  const mounted = useRef(true);
  const form = useRef(null);
  const review = useRef(null);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; sequence.current++; request.current?.abort(); }; }, []);
  useEffect(() => {
    if (!until) return;
    const tick = () => setRemaining(Math.max(0, Math.ceil((until - Date.now()) / 1000)));
    tick(); const timer = setInterval(tick, 1000); return () => clearInterval(timer);
  }, [until]);
  function invalidate() {
    sequence.current++; request.current?.abort(); setImpacto(null); setAceptado(false); setConfirm(false); setBusy('');
  }
  function report(error, kind) {
    const next = describirError(error, { operation: 'asignacion', allowedFields: ['id_consultorio', 'actualizado_en_original', 'huella_impacto', 'confirmar_traslados', 'page', 'per_page'] });
    const code = error.response?.data?.error?.code;
    if (next.status === 409 && Object.hasOwn(mensajes, code)) next.message = mensajes[code];
    if (next.status === 404) next.message = 'No se encontró el médico o consultorio solicitado. Consulta los datos actuales.';
    if (kind === 'write' && (!next.status || next.status >= 500)) {
      next.message = 'No pudimos confirmar la asignación. Puede haberse guardado. Consulta la asignación actual antes de decidir otro envío.'; setBlocked(true);
    } else if (!next.status) next.message = 'No pudimos consultar el impacto o la asignación. Comprueba tu conexión y vuelve a consultar.';
    if (['MEDICO_EDICION_OBSOLETA'].includes(code) || [403, 404].includes(next.status)) setBlocked(true);
    if (next.retryAfter) { setUntil(Date.now() + next.retryAfter * 1000); setRemaining(next.retryAfter); }
    setFailure(next);
    requestAnimationFrame(() => form.current?.querySelector('[role="alert"]')?.focus());
  }
  async function preview(page = 1) {
    if (locked.current || blocked || remaining) return;
    if (!destino) { setFailure({ message: 'Selecciona un consultorio antes de consultar el impacto.', fields: { id_consultorio: 'Selecciona un consultorio.' } }); return; }
    request.current?.abort(); const controller = new AbortController(); request.current = controller;
    const token = ++sequence.current;
    const huella = impacto?.data.huella_impacto;
    setBusy('preview'); setFailure(null); setNotice(''); setConfirm(false); setAceptado(false);
    // No se puede confirmar mientras se consulta otra página.
    setImpacto(null);
    try {
      const result = await consultarImpactoAsignacion(id, { id_consultorio: destino.id_consultorio, actualizado_en_original: base.asignacion.actualizado_en, page }, controller.signal);
      if (!mounted.current || token !== sequence.current) return;
      if (page > 1 && huella !== result.data.huella_impacto) {
        setFailure({ message: 'El impacto cambió entre páginas. Se descartó la revisión y el consentimiento anteriores. Consulta el impacto desde la primera página.', fields: {} });
      } else {
        setImpacto(result); requestAnimationFrame(() => review.current?.focus());
      }
    } catch (error) { if (mounted.current && token === sequence.current && !controller.signal.aborted) report(error, 'preview'); }
    finally { if (mounted.current && token === sequence.current) setBusy(''); }
  }
  async function refresh() {
    if (locked.current || remaining) return;
    invalidate(); const token = sequence.current; const controller = new AbortController(); request.current = controller;
    setBusy('refresh'); setFailure(null);
    try {
      const current = await contexto(id, controller.signal);
      if (!mounted.current || token !== sequence.current) return;
      setBase(current); setBlocked(false); setNotice('Asignación actual consultada. Revisa el destino y solicita un nuevo impacto antes de confirmar.');
    } catch (error) { if (mounted.current && token === sequence.current && !controller.signal.aborted) report(error, 'refresh'); }
    finally { if (mounted.current && token === sequence.current) setBusy(''); }
  }
  const necesitaConsentimiento = (impacto?.data.totales.citas_trasladadas || 0) > 0;
  const listo = !busy && !blocked && !remaining && impacto?.data.puede_confirmar === true && destino && (!necesitaConsentimiento || aceptado);
  async function save(event) {
    event.preventDefault();
    if (locked.current || !listo || !confirm) return;
    locked.current = true; setBusy('write'); setFailure(null); setNotice('');
    try {
      const result = await confirmarAsignacion(id, { id_consultorio: destino.id_consultorio, actualizado_en_original: base.asignacion.actualizado_en,
        huella_impacto: impacto.data.huella_impacto, confirmar_traslados: aceptado });
      if (!mounted.current) return;
      setBase(previous => ({ ...previous, asignacion: result })); setImpacto(null); setConfirm(false); setAceptado(false); setBlocked(true);
      setNotice(`Asignación guardada. Citas con cambio de ubicación: ${result.citas_trasladadas}. Consulta los datos actuales antes de iniciar otra operación.`);
    } catch (error) { if (mounted.current) { setImpacto(null); setConfirm(false); setAceptado(false); report(error, 'write'); } }
    finally { locked.current = false; if (mounted.current) setBusy(''); }
  }
  const sala = base.asignacion.consultorio;
  return <form ref={form} className="catalog-panel assignment-form" aria-label="Asignar consultorio fijo" aria-busy={Boolean(busy)} onSubmit={save}>
    <h2>{base.medico.nombres} {base.medico.apellidos}</h2>
    <p>{base.medico.activo ? 'Médico activo' : 'Médico inactivo'} · {base.medico.codigo_medico}</p>
    <dl className="catalog-data"><div><dt>Consultorio fijo actual</dt><dd>{sala ? `${sala.codigo_consultorio} — ${sala.nombre}${sala.activo ? '' : ' (inactivo)'}` : 'Sin consultorio asignado'}</dd></div></dl>
    <p className="catalog-note">Los horarios activos reservan su franja incluso si el Médico está inactivo. Esta operación no activa médicos, cuentas ni horarios. No permite retirar la asignación ni cambiar fechas, duración o estado de citas.</p>
    {notice && <Alert variant="success">{notice}</Alert>}
    <ConsultorioFailure failure={failure} remaining={remaining} />
    <ConsultorioSelector value={destino} disabled={busy === 'write' || busy === 'refresh'} error={failure?.fields?.id_consultorio} onChange={value => {
      invalidate(); setDestino(value); setFailure(null); setNotice('');
    }} />
    <div className="catalog-actions"><Button variant="secondary" disabled={Boolean(busy) || blocked || remaining > 0} onClick={() => preview()}>Consultar impacto</Button>
      <Button variant="quiet" disabled={Boolean(busy) || remaining > 0} onClick={refresh}>Consultar asignación actual</Button></div>
    {busy && <p role="status">{busy === 'write' ? 'Guardando asignación…' : busy === 'refresh' ? 'Consultando datos actuales…' : 'Consultando impacto…'}</p>}
    <div ref={review} tabIndex="-1">{impacto && <AsignacionImpacto impacto={impacto} disabled={Boolean(busy)} onPage={preview} />}</div>
    {impacto?.data.puede_confirmar && <section className="catalog-confirm" aria-label="Confirmación de asignación">
      <h2>Confirmar la propuesta revisada</h2>
      <p>Destino: <strong>{destino.codigo_consultorio} — {destino.nombre}</strong>. La revisión comprende todas las páginas y no permite seleccionar solo algunas citas.</p>
      <p>Las citas pasadas y finales conservan su ubicación histórica. Solo se trasladan las citas futuras elegibles que el servidor haya identificado.</p>
      {necesitaConsentimiento && <label className="assignment-consent"><input type="checkbox" checked={aceptado} disabled={Boolean(busy)} onChange={event => { setAceptado(event.target.checked); setConfirm(false); }} />Acepto cambiar la ubicación de las {impacto.data.totales.citas_trasladadas} citas propuestas, conservando fecha, hora, duración, estado e historial.</label>}
      {confirm && <p role="status">Revisaste el destino y el impacto. Confirma para solicitar el guardado; el servidor volverá a validar toda la operación.</p>}
      <div className="catalog-actions">{confirm ? <><Button type="submit" loading={busy === 'write'} disabled={!listo}>Guardar asignación</Button><Button variant="quiet" disabled={Boolean(busy)} onClick={() => setConfirm(false)}>Volver a revisar</Button></>
        : <Button disabled={!listo} onClick={() => setConfirm(true)}>Revisar confirmación</Button>}</div>
    </section>}
  </form>;
}
