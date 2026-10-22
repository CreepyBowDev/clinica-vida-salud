import { useEffect, useRef } from 'react';
import Button from '../../../components/Button';
import Alert from '../../../components/Alert';

function hora(value) {
  return new Intl.DateTimeFormat('es-BO', { dateStyle: 'long', timeStyle: 'medium', hourCycle: 'h23', timeZone: 'America/La_Paz' }).format(new Date(value));
}
export default function ReservaRegistro({ registro, recepcion, onVolver }) {
  const heading = useRef(null);
  const { status, propuesta: p, cita, failure, remaining } = registro;
  useEffect(() => { heading.current?.focus(); }, [status, failure]);
  const busy = ['preparando', 'guardando', 'consultando'].includes(status);
  return <section className="reserva-section" aria-busy={busy}>
    <h2 ref={heading} tabIndex="-1">{status === 'preparando' ? 'Preparando revisión' : status === 'registrada' ? 'Cita registrada'
      : status === 'incierto' || status === 'consultando' ? 'Consultar resultado de la reserva' : status === 'error' ? 'Revisa la selección' : 'Revisar cita'}</h2>
    {failure && <Alert><p>{failure.message}</p>{Object.entries(failure.fields).filter(([key]) => key === 'fecha' || key === 'motivo_breve').map(([key, text]) => <p key={key}>{key === 'fecha' ? 'Fecha' : 'Motivo'}: {text}</p>)}</Alert>}
    {remaining > 0 && <p role="status">Espera {remaining} segundos antes de volver a solicitarlo.</p>}
    {status === 'preparando' && <p role="status">Comprobando el turno y preparando el resumen…</p>}
    {p && failure?.status !== 403 && <>
      <dl className="reserva-context">
        <div><dt>Paciente</dt><dd>{p.paciente.nombres} {p.paciente.apellidos} · {p.paciente.codigo_paciente}</dd></div>
        <div><dt>Especialidad</dt><dd>{p.especialidad.nombre}</dd></div>
        <div><dt>Médico</dt><dd>{p.medico.nombres} {p.medico.apellidos}</dd></div>
        <div><dt>Inicio de atención</dt><dd><time dateTime={p.inicio_at}>{hora(p.inicio_at)}</time></dd></div>
        <div><dt>Fin de atención</dt><dd><time dateTime={p.fin_at}>{hora(p.fin_at)}</time></dd></div>
        <div><dt>Duración</dt><dd>{p.duracion_minutos} minutos</dd></div>
        <div><dt>Consultorio</dt><dd>{p.consultorio.codigo_consultorio} — {p.consultorio.nombre}</dd></div>
        <div><dt>Sede</dt><dd>{p.sede_atencion}</dd></div>
        <div><dt>Dirección de la sede</dt><dd>{p.direccion_sede || 'No informada'}</dd></div>
        <div><dt>Modalidad</dt><dd>{p.modalidad}</dd></div>
        <div><dt>Motivo breve</dt><dd>{p.motivo_breve ?? 'No indicado'}</dd></div>
      </dl>
      <p>Horario de la sede: {p.zona_horaria}. El turno no queda reservado hasta completar el registro.</p>
    </>}
    {status === 'ausente' && <Alert variant="info"><p>No se encontró una cita registrada para esta propuesta al consultar. Puedes volver a buscar o decidir confirmar expresamente el mismo resumen. El servidor volverá a comprobar sus condiciones.</p></Alert>}
    {status === 'incierto' && <Alert variant="warning"><p>La reserva podría haberse guardado. No inicies otra sin comprobar el resultado. Si sales o recargas, esta comprobación no se conservará.</p></Alert>}
    {status === 'guardando' && <p role="status">Registrando la cita. No envíes otra solicitud…</p>}
    {status === 'consultando' && <p role="status">Consultando si la reserva quedó registrada…</p>}
    {cita && <>
      <Alert variant="success"><p>{registro.repetida ? 'Se recuperó la cita registrada, sin crear otra.' : 'La cita se registró correctamente.'}</p></Alert>
      <dl className="reserva-context"><div><dt>Código de cita</dt><dd>{cita.codigo_cita}</dd></div>
        <div><dt>Estado vigente</dt><dd>{cita.estado}</dd></div>
        <div><dt>Inicio de atención</dt><dd><time dateTime={cita.inicio_at}>{hora(cita.inicio_at)}</time></dd></div>
        <div><dt>Fin de atención</dt><dd><time dateTime={cita.fin_at}>{hora(cita.fin_at)}</time></dd></div>
        <div><dt>Duración registrada</dt><dd>{cita.duracion_minutos} minutos</dd></div>
        <div><dt>Consultorio registrado</dt><dd>{cita.consultorio_atencion}</dd></div>
        <div><dt>Sede registrada</dt><dd>{cita.sede_atencion}</dd></div>
        <div><dt>Modalidad</dt><dd>{cita.modalidad}</dd></div>
        <div><dt>Motivo breve</dt><dd>{cita.motivo_breve ?? 'No indicado'}</dd></div></dl>
      <p>Horario de America/La_Paz. Registrar la cita no confirma la llegada a la clínica.</p>
    </>}
    <div className="reserva-filters">
      {['revision', 'ausente'].includes(status) && <Button disabled={busy || remaining > 0} onClick={registro.confirmar}>
        {status === 'ausente' ? 'Confirmar nuevamente esta propuesta' : recepcion ? 'Programar cita' : 'Reservar cita'}</Button>}
      {['incierto', 'ausente'].includes(status) && <Button disabled={busy || remaining > 0 || failure?.status === 403} onClick={registro.consultar}>Consultar resultado</Button>}
      {!busy && status !== 'incierto' && <Button variant="secondary" disabled={remaining > 0} onClick={onVolver}>
        {status === 'registrada' ? 'Buscar otro turno' : 'Volver a buscar y revisar'}</Button>}
    </div>
  </section>;
}
