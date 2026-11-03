import { useEffect, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import ActionGroup from '../../../components/ActionGroup';
import QueryMessage from '../../../components/QueryMessage';
import Pagination from '../../../components/Pagination';
import { useAgendaQuery } from '../../agenda/hooks/useAgendaQuery';
import { esFechaCivil, formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarDisponibilidadReprogramacion } from '../services/gestionCita.service';
import '../styles/reserva.css';

export default function ReprogramacionCita({ original, onGuardar }) {
  const [fecha, setFecha] = useState('');
  const [error, setError] = useState('');
  const [criterios, setCriterios] = useState(null);
  const [revision, setRevision] = useState(0);
  const [page, setPage] = useState(1);
  // La consulta vive durante todo el formulario: editar fecha no borra Retry-After.
  const query = useAgendaQuery(consultarDisponibilidadReprogramacion, { id: original.id_cita, fecha: criterios?.fecha ?? '', original, page, per_page: 12 },
    { enabled: criterios !== null, kind: 'reprogramacion', allowedFields: ['fecha', 'page', 'per_page'] });
  return <section className="reserva-section" aria-labelledby="reprogramacion-titulo"><h2 id="reprogramacion-titulo">Reprogramar cita</h2>
    <p>Se conservan la cita, el Paciente, el Médico y la especialidad. Una ficha inactiva no impide gestionar esta misma cita.</p>
    <form className="reserva-filters" aria-label="Consultar alternativas de reprogramación" noValidate onSubmit={event => {
      event.preventDefault(); setCriterios(null);
      if (query.remaining > 0) return;
      if (!esFechaCivil(fecha)) { setError('Selecciona una fecha válida.'); return; }
      setError(''); setPage(1); setCriterios({ fecha }); setRevision(value => value + 1); query.reload();
    }}>
      <FormField label="Nueva fecha de atención" type="date" value={fecha} error={error} onChange={event => {
        setFecha(event.target.value); setError(''); setCriterios(null);
      }} />
      <Button type="submit" disabled={query.remaining > 0}>Consultar alternativas</Button>
      {query.remaining > 0 && <p>Espera {query.remaining} segundos antes de consultar alternativas.</p>}
    </form>
    <div className="reschedule-alternatives" role="region" aria-label="Alternativas de reprogramación">
      {criterios && <Alternativas key={`${criterios.fecha}:${revision}:${page}`} query={query} onPage={setPage} onGuardar={onGuardar} />}
    </div>
  </section>;
}

function Alternativas({ query, onPage, onGuardar }) {
  const [seleccion, setSeleccion] = useState(null), [revisar, setRevisar] = useState(false);
  const heading = useRef(null);
  const reviewButton = useRef(null);
  const data = query.data?.data;
  const limpiar = () => { setSeleccion(null); setRevisar(false); };
  useEffect(() => { if (revisar) heading.current?.focus(); }, [revisar]);
  if (query.loading) return <QueryMessage loading title="Consultando alternativas…" />;
  if (query.failure) return <div className="catalog-failure"><Alert><p>{query.failure.message}</p>
    <p>Si la programación cambió, usa Actualizar gestión y revisa de nuevo la cita.</p>
    {query.failure.fields.fecha && <p>{query.failure.fields.fecha}</p>}</Alert>
    {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={() => { limpiar(); query.reload(); }}>Volver a consultar alternativas</Button>}
  </div>;
  return <div>
    <p>Disponibilidad orientativa en {data.zona_horaria}. Elegir un turno no modifica ni reserva la cita.</p>
    {data.turnos.length === 0 ? <QueryMessage title="No hay alternativas para esta fecha y página" description="Selecciona otra fecha o vuelve a una página anterior." />
      : <fieldset className="reserva-slots"><legend>Nuevo turno de atención</legend><div className="reserva-slot-grid">
        {data.turnos.map(slot => <label key={slot.inicio_at}><input type="radio" name="reprogramacion-turno" value={slot.inicio_at}
          checked={seleccion?.inicio_at === slot.inicio_at} onChange={() => { setSeleccion(slot); setRevisar(false); }} />
        <span>{slot.hora_inicio}</span></label>)}
      </div></fieldset>}
    <Pagination meta={query.data.meta} label="alternativas" onPage={next => { limpiar(); onPage(next); }} />
    <Button variant="secondary" onClick={() => { limpiar(); query.reload(); }}>Actualizar alternativas</Button>
    {seleccion && !revisar && <Button ref={reviewButton} onClick={() => setRevisar(true)}>Revisar reprogramación</Button>}
    {seleccion && revisar && <section className="catalog-panel reschedule-review" aria-labelledby="reprogramacion-revision">
      <h3 id="reprogramacion-revision" tabIndex="-1" ref={heading}>Revisar reprogramación</h3>
      <p>Paciente: {data.paciente.nombres} {data.paciente.apellidos}. Médico: {data.medico.nombres} {data.medico.apellidos}. Especialidad: {data.especialidad.nombre}.</p>
      <dl className="catalog-data record-data">
        <div><dt>Programación anterior</dt><dd>{formatearInstanteCita(data.cita_original.inicio_at)} — {formatearInstanteCita(data.cita_original.fin_at)} · {data.cita_original.duracion_minutos} minutos</dd></div>
        <div><dt>Ubicación anterior</dt><dd>{data.cita_original.sede_atencion} · {data.cita_original.consultorio_atencion}</dd></div>
        <div><dt>Nueva programación</dt><dd>{formatearInstanteCita(seleccion.inicio_at)} — {formatearInstanteCita(seleccion.fin_at)} · {data.duracion_minutos} minutos</dd></div>
        <div><dt>Nueva ubicación</dt><dd>{data.sede_atencion} · {data.consultorio.codigo_consultorio} — {data.consultorio.nombre}{data.direccion_sede && ` · ${data.direccion_sede}`}</dd></div>
      </dl>
      <p>{data.cita_original.estado === 'Confirmada' ? 'La cita Confirmada volverá a Programada y perderá la confirmación de llegada anterior.' : 'La cita permanecerá Programada.'}</p>
      <p>El servidor volverá a comprobar disponibilidad y condiciones al guardar. No se crea otra cita.</p>
      <ActionGroup><Button onClick={() => onGuardar({ vista: data, turno: seleccion })}>Confirmar reprogramación</Button>
         <Button variant="quiet" onClick={() => { setRevisar(false); requestAnimationFrame(() => reviewButton.current?.focus()); }}>Volver sin guardar</Button></ActionGroup>
    </section>}
  </div>;
}
