import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

const impedimentos = {
  CITA_GESTION_NO_PERMITIDA: 'El estado actual no permite reprogramar ni cancelar esta cita.',
  CITA_PLAZO_GESTION_FINALIZADO: 'El plazo para gestionar la cita ha finalizado.',
  CITA_CON_ATENCION_DOCUMENTADA: 'La cita tiene documentación de atención asociada y no admite estos cambios.',
};
const operaciones = { REPROGRAMACION: 'Reprogramación', CANCELACION: 'Cancelación', CAMBIO_UBICACION: 'Cambio de ubicación' };
const instante = value => <time dateTime={value}>{formatearInstanteCita(value)}</time>;

export default function GestionCitaResumen({ contexto }) {
  const { gestion: cita, paciente, medico, especialidad } = contexto;
  return <div className="agenda-detail-sections">
    <section className="catalog-panel" aria-labelledby="gestion-programacion"><h2 id="gestion-programacion">Programación actual: {cita.codigo_cita}</h2>
      <dl className="catalog-data record-data">
        <div><dt>Paciente</dt><dd>{paciente.nombres} {paciente.apellidos}</dd></div>
        <div><dt>Médico responsable</dt><dd>{medico.nombres} {medico.apellidos}</dd></div>
        <div><dt>Especialidad</dt><dd>{especialidad.nombre}</dd></div>
        <div><dt>Estado</dt><dd>{cita.estado}</dd></div>
        <div><dt>Inicio</dt><dd>{instante(cita.inicio_at)}</dd></div><div><dt>Fin</dt><dd>{instante(cita.fin_at)}</dd></div>
        <div><dt>Duración</dt><dd>{cita.duracion_minutos} minutos</dd></div><div><dt>Modalidad</dt><dd>{cita.modalidad}</dd></div>
        <div><dt>Sede</dt><dd>{cita.sede_atencion}</dd></div><div><dt>Consultorio de la cita</dt><dd>{cita.consultorio_atencion}</dd></div>
        <div><dt>Versión de programación</dt><dd>{cita.version_programacion}</dd></div>
        <div><dt>Última actualización</dt><dd>{instante(cita.actualizada_en)}</dd></div>
      </dl><p>Consulta realizada: {instante(cita.consultado_en)} · {cita.zona_horaria}</p>
    </section>
    <section className="catalog-panel" aria-labelledby="gestion-acciones"><h2 id="gestion-acciones">Condiciones de gestión</h2>
      {cita.impedimentos.length ? <ul>{cita.impedimentos.map(code => <li key={code}>{impedimentos[code]}</li>)}</ul>
        : <p>No hay impedimentos básicos de estado, plazo o documentación en esta consulta.</p>}
      <p>Reprogramación: {cita.acciones.reprogramar ? 'admite consultar alternativas' : 'no permitida'}.</p>
      <p>Cancelación: {cita.acciones.cancelar ? 'sin impedimentos básicos' : 'no permitida'}.</p>
      <p>La reprogramación también requiere verificar los recursos y la disponibilidad al guardar. Esta consulta no reserva un turno.</p>
      <p>Las operaciones requieren revisión y confirmación explícitas. Cancelar conserva el registro histórico.</p>
    </section>
    <section className="catalog-panel" aria-labelledby="gestion-ultimo-cambio"><h2 id="gestion-ultimo-cambio">Último cambio registrado</h2>
      {cita.ultimo_cambio ? <><p>{operaciones[cita.ultimo_cambio.operacion] ?? 'Cambio administrativo'} · {instante(cita.ultimo_cambio.realizado_en)}</p>
        <p>Usuario ejecutor (identificador): {cita.ultimo_cambio.id_actor}. No equivale al Médico responsable.</p></>
        : <p>No hay cambios de gestión registrados.</p>}
      <p>Se muestra el estado persistido actual, no la confirmación de que una petición anterior concreta lo haya causado.</p>
    </section>
  </div>;
}
