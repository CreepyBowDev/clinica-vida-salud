import { useRef, useState } from 'react';
import CatalogResults from '../../../components/CatalogResults';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { consultarDisponibilidad } from '../services/reserva.service';
import { normalizarMotivo } from '../services/reserva.service';

const campos = ['fecha', 'id_medico', 'id_especialidad', 'id_paciente', 'page', 'per_page'];
export default function DisponibilidadTurnos({ criterios, onRevisar }) {
  const [page, setPage] = useState(1);
  const [turno, setTurno] = useState(null);
  const [motivo, setMotivo] = useState('');
  const [errorMotivo, setErrorMotivo] = useState('');
  const query = useCatalogQuery(consultarDisponibilidad, { ...criterios, page, per_page: 12 }, campos);
  const heading = useRef(null);
  const data = query.data?.data;
  const adapted = { ...query, data: query.data ? { ...query.data, data: data.turnos } : undefined,
    reload: () => { setTurno(null); query.reload(); } };
  const failure = query.failure;
  if (failure && [403, 404, 409].includes(failure.status)) {
    return <section className="reserva-section"><h2>Turnos disponibles</h2><Alert><p>{failure.status === 403 ? 'No tienes permiso para consultar esta selección.'
      : 'La selección ya no está disponible. Revisa el Paciente, la especialidad y el Médico antes de volver a consultar.'}</p></Alert></section>;
  }
  return <section className="reserva-section" aria-busy={query.loading}>
    <h2 ref={heading} tabIndex="-1">Turnos disponibles</h2>
    <p>La disponibilidad es orientativa. Seleccionar un turno no registra ni garantiza una cita.</p>
    {failure?.fields.fecha && <p className="field-error">Fecha de atención: {failure.fields.fecha}</p>}
    {data && <>
      <dl className="reserva-context"><div><dt>Médico</dt><dd>{data.medico.nombres} {data.medico.apellidos}</dd></div>
        <div><dt>Especialidad</dt><dd>{data.especialidad.nombre}</dd></div>
        <div><dt>Consultorio</dt><dd>{data.consultorio.codigo_consultorio} — {data.consultorio.nombre}</dd></div>
        <div><dt>Duración vigente</dt><dd>{data.duracion_minutos} minutos</dd></div></dl>
      <p>Fecha: {data.fecha} · Hora de la sede: {data.zona_horaria}</p>
      {!data.paciente_reservable && <Alert variant="warning"><p>La ficha del Paciente está inactiva. Puedes consultar los turnos, pero no realizar una nueva reserva.</p></Alert>}
    </>}
    <CatalogResults query={adapted} label="turnos" loadingLabel="Consultando turnos…"
      emptyTitle="No hay turnos disponibles para esta fecha" emptyHint="Consulta otra fecha o selecciona otro Médico."
      onPage={next => { setTurno(null); setPage(next); heading.current?.focus(); }}>
      <fieldset className="reserva-slots" disabled={!data?.paciente_reservable}>
        <legend>Selecciona un turno del {data?.fecha}</legend>
        <div className="reserva-slot-grid">{data?.turnos.map(slot => <label key={slot.inicio_at}>
          <input type="radio" name="turno" value={slot.inicio_at} checked={turno?.inicio_at === slot.inicio_at}
            onChange={() => setTurno(slot)} />
          <span>{slot.hora_inicio}</span>
        </label>)}</div>
      </fieldset>
    </CatalogResults>
    {turno && data?.paciente_reservable && <p role="status" className="reserva-selection">Turno seleccionado: {data.fecha}, {turno.hora_inicio} (hora de la sede). Todavía no hay una cita registrada.</p>}
    {data && <Button variant="secondary" disabled={query.remaining > 0} onClick={() => { setTurno(null); query.reload(); }}>Actualizar disponibilidad</Button>}
    {turno && data?.paciente_reservable && <form className="reserva-reason" aria-label="Revisar turno seleccionado" noValidate onSubmit={e => {
      e.preventDefault();
      try {
        const motivo_breve = normalizarMotivo(motivo); setErrorMotivo('');
        onRevisar({ ...criterios, hora_inicio: turno.hora_inicio, revision_turno: turno.revision_turno, motivo_breve, inicioEsperado: turno.inicio_at });
      } catch { setErrorMotivo('Introduce hasta 500 caracteres, sin saltos de línea ni caracteres de control.'); }
    }}>
      <label htmlFor="reserva-motivo">Motivo breve (opcional)</label>
      <input id="reserva-motivo" type="text" autoComplete="off" value={motivo} aria-invalid={Boolean(errorMotivo)}
        aria-describedby={errorMotivo ? 'reserva-motivo-error' : 'reserva-motivo-ayuda'} onChange={e => { setMotivo(e.target.value); setErrorMotivo(''); }} />
      <p id="reserva-motivo-ayuda" className="field-hint">Hasta 500 caracteres. Se mostrará antes de registrar la cita.</p>
      {errorMotivo && <p id="reserva-motivo-error" className="field-error" role="alert">{errorMotivo}</p>}
      <Button type="submit">Revisar cita</Button>
    </form>}
  </section>;
}
