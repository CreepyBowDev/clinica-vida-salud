import { useEffect, useRef, useState } from 'react';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

export default function ConfirmacionAsistencia({ contexto, onGuardar, operacion = 'LLEGADA' }) {
  const [declaracion, setDeclaracion] = useState(false), [revision, setRevision] = useState(false), [error, setError] = useState('');
  const checkbox = useRef(null), heading = useRef(null);
  const { asistencia: cita, paciente, medico } = contexto;
  const ausencia = operacion === 'INASISTENCIA';
  const atencion = operacion === 'ATENCION';
  const nombre = atencion ? 'atención' : ausencia ? 'inasistencia' : 'llegada';
  const declaracionTexto = atencion ? 'He comprobado que el Médico responsable realizó efectivamente la atención de esta cita.' : ausencia ? 'He comprobado que el paciente no se presentó a esta cita.' : 'He verificado presencialmente la presencia y la identidad del paciente.';
  useEffect(() => { if (revision) heading.current?.focus(); }, [revision]);
  useEffect(() => {
    const clear = () => { setDeclaracion(false); setRevision(false); setError(''); };
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  const volver = () => { setDeclaracion(false); setRevision(false); setError(''); checkbox.current?.focus(); };
  return <section className="catalog-panel attendance-operation" aria-labelledby={`${nombre}-titulo`}><h2 id={`${nombre}-titulo`}>{atencion ? 'Registrar atención realizada' : ausencia ? 'Registrar ausencia comprobada' : 'Confirmar llegada presencial'}</h2>
    <form aria-label={`Revisar ${nombre}`} noValidate onSubmit={event => {
      event.preventDefault();
      if (!declaracion) { setError(atencion ? 'Comprueba que la atención fue efectivamente realizada antes de continuar.' : ausencia ? 'Comprueba que el paciente no se presentó antes de continuar.' : 'Comprueba presencialmente la presencia y la identidad antes de continuar.'); checkbox.current?.focus(); return; }
      setError(''); setRevision(true);
    }}>
      <label className="asistencia-declaracion"><input ref={checkbox} type="checkbox" checked={declaracion} aria-invalid={Boolean(error)}
        aria-describedby={error ? `${nombre}-error` : undefined} onChange={event => { setDeclaracion(event.target.checked); setRevision(false); setError(''); }} />
        <span>{declaracionTexto}</span></label>
      {error && <p id={`${nombre}-error`} className="field-error">{error}</p>}
      <Button type="submit">Revisar {nombre}</Button>
    </form>
    {revision && <section aria-labelledby={`${nombre}-revision`}><h3 id={`${nombre}-revision`} tabIndex="-1" ref={heading}>Revisar {nombre} de {cita.codigo_cita}</h3>
      <dl className="catalog-data record-data">
        <div><dt>Paciente</dt><dd>{paciente.nombres} {paciente.apellidos}</dd></div>
        <div><dt>Médico responsable</dt><dd>{medico.nombres} {medico.apellidos}</dd></div>
        <div><dt>Especialidad</dt><dd>{contexto.especialidad.nombre}</dd></div><div><dt>Estado revisado</dt><dd>{cita.estado}</dd></div>
        <div><dt>Inicio</dt><dd>{formatearInstanteCita(cita.inicio_at)}</dd></div><div><dt>Fin</dt><dd>{formatearInstanteCita(cita.fin_at)}</dd></div>
        <div><dt>Duración</dt><dd>{cita.duracion_minutos} minutos</dd></div><div><dt>Ubicación conservada</dt><dd>{cita.sede_atencion} · {cita.consultorio_atencion}</dd></div>
      </dl>
      <Alert variant="warning">{atencion ? <><p>Has declarado atención efectivamente realizada. Solo si el servidor confirma, la cita pasará de {cita.estado} a Atendida.</p>
        <p>Atendida es un estado final sin reapertura ordinaria. Se conservan la programación y el historial; se retiran los avisos propios vigentes.</p>
        <p>El usuario ejecutor conserva su identidad y no sustituye al Médico responsable. Esta operación no crea ni modifica consultas, antecedentes ni resultados clínicos.</p>
        <p>El tiempo transcurrido o una consulta existente no demuestran automáticamente la atención.</p></> : ausencia ? <><p>Has declarado que el paciente no se presentó. Solo si el servidor confirma, la cita pasará de Programada a Inasistencia.</p>
        <p>Inasistencia es un estado final sin reapertura ordinaria. Se conservan la programación y el historial; se retiran los avisos propios vigentes.</p>
        <p>La ausencia no se deduce automáticamente del tiempo transcurrido ni registra consulta clínica.</p></>
        : <><p>Has declarado presencia e identidad verificadas presencialmente. Solo si el servidor confirma, la cita pasará de Programada a Confirmada.</p>
          <p>Se conserva la programación y sus avisos. No se marca Atendida ni se registra consulta clínica.</p></>}</Alert>
      <ActionGroup><Button variant={ausencia || atencion ? 'danger' : 'primary'} onClick={() => { setDeclaracion(false); setRevision(false); onGuardar({ original: cita, declaracion: true }); }}>{atencion ? 'Marcar como Atendida' : ausencia ? 'Registrar inasistencia' : 'Confirmar llegada'}</Button>
        <Button variant="quiet" onClick={volver}>{ausencia || atencion ? 'Volver sin registrar' : 'Volver sin confirmar'}</Button></ActionGroup>
    </section>}
  </section>;
}
