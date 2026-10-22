import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { useHorarioSubmission } from '../hooks/useHorarioSubmission';
import { consultarConfiguracionCitas, consultarImpactoDuracion, actualizarDuracion } from '../services/configuracionCitas.service';
import HorarioImpacto from '../components/HorarioImpacto';
import '../../../styles/catalogs.css';
import '../styles/horarios.css';

const fields = [];
const load = (_params, signal) => consultarConfiguracionCitas(signal);
export default function ConfiguracionCitasPage() {
  const query = useCatalogQuery(load, null, fields);
  const [notice, setNotice] = useState('');
  return <section className="workspace-page catalog-page"><Link to="/administracion/horarios">Volver a horarios</Link>
    <header className="page-heading"><h1>Duración global de citas</h1><p>Configura la duración para toda la sede. El cambio no modifica las citas existentes.</p></header>
    {notice && <Alert variant="success">{notice}</Alert>}
    {query.loading ? <p role="status">Consultando configuración de citas…</p> : query.failure ? <><Alert>{query.failure.status === 404 ? 'No se encontró la configuración de citas.' : query.failure.message}</Alert><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar configuración</Button></>
      : <DurationEditor key={query.data.actualizado_en} initial={query.data} onReload={() => { setNotice(''); query.reload(); }} onSaved={() => { setNotice('Duración global guardada. Se consultó nuevamente la configuración; las citas existentes conservan su duración.'); query.reload(); }} />}
  </section>;
}
function DurationEditor({ initial, onReload, onSaved }) {
  const [draft, setDraft] = useState(String(initial.duracion_turno_minutos));
  const [impacto, setImpacto] = useState(null), [confirm, setConfirm] = useState(false);
  const form = useRef(null), review = useRef(null);
  const { busy, failure, blocked, remaining, execute, cancel, report } = useHorarioSubmission(form, true);
  function proposed() {
    const value = Number(draft);
    if (!/^\d+$/.test(draft) || !Number.isInteger(value) || value < 1 || value > 2147483647) {
      report({ message: 'Revisa la duración propuesta.', fields: { duracion_turno_minutos: 'Introduce minutos enteros entre 1 y 2147483647.' } }); return null;
    }
    if (value === initial.duracion_turno_minutos) { report({ message: 'No hay cambios para guardar.', fields: {} }); return null; }
    return { duracion_turno_minutos: value, actualizado_en_original: initial.actualizado_en };
  }
  async function preview(page = 1) {
    if (busy || blocked || remaining) return;
    const data = proposed(); if (!data) return;
    setImpacto(null); setConfirm(false);
    const result = await execute('preview', signal => consultarImpactoDuracion({ ...data, page }, signal));
    if (result?.data) { setImpacto(result.data); requestAnimationFrame(() => review.current?.focus()); }
  }
  async function save(event) {
    event.preventDefault();
    if (busy || blocked || remaining || !confirm || !impacto?.data.puede_confirmar) return;
    const data = proposed(); if (!data) return;
    setConfirm(false); setImpacto(null);
    const result = await execute('write', () => actualizarDuracion(data));
    if (result?.data) onSaved();
  }
  return <form ref={form} className="catalog-panel catalog-editor horario-editor" aria-label="Configurar duración global" onSubmit={save} noValidate aria-busy={Boolean(busy)}>
    <h2>Configuración vigente</h2>
    <dl className="catalog-data"><div><dt>Duración actual</dt><dd>{initial.duracion_turno_minutos} minutos</dd></div><div><dt>Zona horaria de la sede</dt><dd>{initial.zona_horaria}</dd></div></dl>
    <p className="catalog-note">La duración es común a todos los Médicos y especialidades. Debe caber en cada intervalo activo, también si el Médico está inactivo. No exige división exacta del horario: solo se ofrecen turnos completos y se descarta el sobrante.</p>
    {failure && <Alert tabIndex="-1"><p>{failure.message}</p>{remaining > 0 && <p>Espera {remaining} segundos antes de volver a intentarlo.</p>}</Alert>}
    <fieldset className="catalog-editor-fields" disabled={busy === 'write' || blocked}><legend>Proponer nueva duración</legend>
      <FormField label="Duración propuesta en minutos" type="text" inputMode="numeric" autoComplete="off" required value={draft}
        onChange={event => { cancel(); setDraft(event.target.value); setImpacto(null); setConfirm(false); report(null); }} error={failure?.fields?.duracion_turno_minutos}
        hint="Minutos enteros positivos. Consulta el impacto para comprobar que caben en todos los horarios activos." />
    </fieldset>
    <p className="catalog-note">Las nuevas reservas aplican la configuración vigente. Recepción aplica la duración vigente al reprogramar por el flujo autorizado; desde aquí no se cambian citas ni horarios.</p>
    {blocked && <p>La propuesta sigue visible, pero no puede reenviarse. Descártala explícitamente y consulta la configuración actual para revisar el estado guardado.</p>}
    <div className="catalog-actions"><Button variant="secondary" disabled={Boolean(busy) || blocked || remaining > 0} onClick={() => preview()}>Consultar impacto de duración</Button>
      <Button variant="quiet" disabled={Boolean(busy) || remaining > 0} onClick={onReload}>Descartar propuesta y consultar configuración actual</Button></div>
    {busy && <p role="status">{busy === 'write' ? 'Guardando duración global…' : 'Consultando impacto de duración…'}</p>}
    <div ref={review} tabIndex="-1">{impacto && <HorarioImpacto impacto={impacto} configuracion onPage={preview} disabled={Boolean(busy) || remaining > 0} />}</div>
    {impacto?.data.puede_confirmar && <section className="catalog-confirm" aria-label="Confirmación de duración global"><h2>Confirmar cambio global</h2>
      <p>La duración pasará de <strong>{initial.duracion_turno_minutos} a {Number(draft)} minutos</strong> para toda la sede. Las citas ya registradas conservarán su duración almacenada.</p>
      <p>El diagnóstico abarca todos los intervalos activos, no solo esta página. El servidor revalidará la configuración y la compatibilidad antes de guardar.</p>
      {confirm && <p role="status">Revisaste el alcance global del cambio. Confirma para solicitar el guardado.</p>}
      <div className="catalog-actions">{confirm ? <><Button type="submit" loading={busy === 'write'} disabled={Boolean(busy) || blocked || remaining > 0}>Guardar duración global</Button><Button variant="quiet" disabled={Boolean(busy)} onClick={() => setConfirm(false)}>Volver a revisar</Button></>
        : <Button disabled={Boolean(busy) || blocked || remaining > 0} onClick={() => setConfirm(true)}>Revisar cambio global</Button>}</div>
    </section>}
  </form>;
}
