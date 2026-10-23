import { ROLES } from '../../../constants/roles';
import { formatearFechaCivil, formatearInstanteCita } from '../utils/agendaFormat';

function Dato({ label, children }) { return <div><dt>{label}</dt><dd>{children}</dd></div>; }
function Instante({ value }) { return <time dateTime={value}>{formatearInstanteCita(value)}</time>; }

export default function CitaDetalle({ cita, rol }) {
  return <div className="agenda-detail-sections">
    <section className="catalog-panel" aria-labelledby="cita-atencion"><h2 id="cita-atencion">Cita {cita.codigo_cita}</h2>
      <dl className="catalog-data record-data">
        <Dato label="Estado"><span className="agenda-state">{cita.estado}</span></Dato>
        <Dato label="Inicio"><Instante value={cita.inicio_at} /></Dato><Dato label="Fin"><Instante value={cita.fin_at} /></Dato>
        <Dato label="Duración">{cita.duracion_minutos} minutos</Dato><Dato label="Modalidad">{cita.modalidad}</Dato>
        <Dato label="Sede">{cita.sede_atencion}</Dato><Dato label="Consultorio de la cita">{cita.consultorio_atencion}</Dato>
        <Dato label="Fecha de registro"><Instante value={cita.creada_en} /></Dato>
      </dl><p className="catalog-note">Confirmada indica llegada presencial verificada por Recepción. El paso del tiempo no cambia el estado de la cita.</p>
    </section>
    <section className="catalog-panel" aria-labelledby="cita-profesional"><h2 id="cita-profesional">Médico y especialidad</h2><dl className="catalog-data record-data">
      <Dato label="Médico">{cita.medico.nombres} {cita.medico.apellidos}</Dato><Dato label="Código de médico">{cita.medico.codigo_medico}</Dato>
      <Dato label="Especialidad">{cita.especialidad.nombre}</Dato>
    </dl></section>
    <section className="catalog-panel" aria-labelledby="cita-paciente"><h2 id="cita-paciente">Paciente</h2><dl className="catalog-data record-data">
      <Dato label="Nombre">{cita.paciente.nombres} {cita.paciente.apellidos}</Dato><Dato label="Código de paciente">{cita.paciente.codigo_paciente}</Dato>
      {rol === ROLES.RECEPCIONISTA && <><Dato label="CI">{cita.paciente.documento_identidad ?? 'Sin CI registrado'}</Dato><Dato label="Teléfono">{cita.paciente.telefono}</Dato></>}
      {rol === ROLES.MEDICO && <Dato label="Fecha de nacimiento">{cita.paciente.fecha_nacimiento
        ? <time dateTime={cita.paciente.fecha_nacimiento}>{formatearFechaCivil(cita.paciente.fecha_nacimiento)}</time> : 'No registrado'}</Dato>}
    </dl></section>
    {rol !== ROLES.ADMINISTRADOR && Object.hasOwn(cita, 'motivo_breve') && <section className="catalog-panel" aria-labelledby="cita-motivo"><h2 id="cita-motivo">Motivo breve</h2>
      <p className="agenda-motivo">{cita.motivo_breve ?? 'Sin motivo registrado'}</p></section>}
  </div>;
}
