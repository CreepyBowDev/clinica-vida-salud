import { ROLES } from '../../../constants/roles';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

const impedimentos = {
  ACCESO_DENEGADO: 'Esta acción no corresponde a tu rol.',
  CITA_TRANSICION_NO_PERMITIDA: 'El estado actual no admite esta transición.',
  CITA_CON_ATENCION_DOCUMENTADA: 'Existe documentación de atención asociada que impide este cambio.',
  CITA_LLEGADA_FUERA_DE_PLAZO: 'La llegada requiere el mismo día local y un intervalo todavía no finalizado.',
  CITA_INASISTENCIA_ANTICIPADA: 'El intervalo de la cita todavía no ha finalizado.',
  CITA_ATENCION_ANTICIPADA: 'La cita todavía no ha alcanzado su inicio.',
};
const cambios = { LLEGADA: 'Llegada confirmada', INASISTENCIA: 'Inasistencia registrada', ATENCION: 'Atención registrada',
  REPROGRAMACION: 'Reprogramación', CANCELACION: 'Cancelación', CAMBIO_UBICACION: 'Cambio de ubicación' };

export default function AsistenciaResumen({ contexto, rol }) {
  const { asistencia: cita, paciente, medico, especialidad } = contexto;
  const acciones = rol === ROLES.MEDICO ? [['marcar_atendida', 'Atendida']]
    : [['confirmar_llegada', 'Llegada'], ['registrar_inasistencia', 'Inasistencia'], ...(rol === ROLES.ADMINISTRADOR ? [['marcar_atendida', 'Atendida']] : [])];
  return <div className="agenda-detail-sections">
    <section className="catalog-panel" aria-labelledby="asistencia-programacion"><h2 id="asistencia-programacion">Programación actual: {cita.codigo_cita}</h2>
      <dl className="catalog-data record-data">
        <div><dt>Paciente</dt><dd>{paciente.nombres} {paciente.apellidos}</dd></div>
        <div><dt>Médico responsable</dt><dd>{medico.nombres} {medico.apellidos}</dd></div>
        <div><dt>Especialidad</dt><dd>{especialidad.nombre}</dd></div><div><dt>Estado</dt><dd>{cita.estado}</dd></div>
        <div><dt>Inicio</dt><dd>{formatearInstanteCita(cita.inicio_at)}</dd></div><div><dt>Fin</dt><dd>{formatearInstanteCita(cita.fin_at)}</dd></div>
        <div><dt>Duración</dt><dd>{cita.duracion_minutos} minutos</dd></div><div><dt>Modalidad</dt><dd>{cita.modalidad}</dd></div>
        <div><dt>Sede</dt><dd>{cita.sede_atencion}</dd></div><div><dt>Consultorio de la cita</dt><dd>{cita.consultorio_atencion}</dd></div>
        <div><dt>Versión de programación</dt><dd>{cita.version_programacion}</dd></div>
        <div><dt>Última actualización</dt><dd>{formatearInstanteCita(cita.actualizada_en)}</dd></div>
      </dl><p>Consulta realizada: {formatearInstanteCita(cita.consultado_en)} · {cita.zona_horaria}</p>
    </section>
    <section className="catalog-panel" aria-labelledby="asistencia-condiciones"><h2 id="asistencia-condiciones">Condiciones de asistencia</h2>
      {acciones.map(([key, label]) => <div key={key}><h3>{label}</h3>
        {cita.impedimentos[key].length ? <ul>{cita.impedimentos[key].map(code => <li key={code}>{impedimentos[code]}</li>)}</ul>
          : <p>Sin impedimentos en esta consulta; el servidor volverá a comprobar las condiciones al guardar.</p>}</div>)}
      <p>Confirmada significa llegada presencial verificada, no reserva registrada. La consulta no prueba presencia, ausencia ni atención.</p>
      <p>Marcar Atendida registra atención declarada, no crea ni modifica documentación clínica.</p>
    </section>
    <section className="catalog-panel" aria-labelledby="asistencia-ultimo"><h2 id="asistencia-ultimo">Último cambio registrado</h2>
      {cita.ultimo_cambio ? <><p>{cambios[cita.ultimo_cambio.operacion] ?? 'Cambio administrativo'} · {formatearInstanteCita(cita.ultimo_cambio.realizado_en)}</p>
        <p>Usuario ejecutor (identificador): {cita.ultimo_cambio.id_actor}. No equivale al Médico responsable.</p></> : <p>No hay cambios registrados.</p>}
      <p>El estado consultado no demuestra qué petición concreta causó el último cambio.</p>
    </section>
  </div>;
}
