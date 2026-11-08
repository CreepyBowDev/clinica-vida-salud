import { useRef } from 'react';
import { Link, matchPath, useLocation } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import Alert from '../../../components/Alert';
import PageHeading from '../../../components/PageHeading';
import WorkflowProgress from '../../../components/WorkflowProgress';
import ActionGroup from '../../../components/ActionGroup';
import ReservaCatalogo from '../components/ReservaCatalogo';
import ReservaHorarioMedico from '../components/ReservaHorarioMedico';
import DisponibilidadTurnos from '../components/DisponibilidadTurnos';
import ReservaRegistro from '../components/ReservaRegistro';
import { useReservaWorkspace } from '../components/ReservaWorkspace';
import { listarEspecialidadesReserva, listarMedicosReserva } from '../services/reserva.service';
import { listarPacientes } from '../../pacientes/services/paciente.service';
import '../../../styles/catalogs.css';
import '../styles/reserva.css';
import '../styles/booking.css';

const especialidadCampos = [{ name: 'nombre', label: 'Nombre de especialidad' }];
const medicoCampos = [{ name: 'nombre', label: 'Nombre del Médico' }, { name: 'consultorio', label: 'Código o nombre del consultorio' }];
const pacienteCampos = [{ name: 'documento_identidad', label: 'CI (si existe)', max: 50 }, { name: 'nombres', label: 'Nombres del Paciente' }, { name: 'apellidos', label: 'Apellidos del Paciente' }];
const etapas = [{ id: 'busqueda', label: 'Buscar turno' }, { id: 'revision', label: 'Revisar y registrar' }, { id: 'resultado', label: 'Resultado de la reserva' }];
function fechaValida(value) {
  if (!/^(?!0000)\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [y, m, d] = value.split('-').map(Number);
  const date = new Date(0); date.setUTCFullYear(y, m - 1, d);
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}
export default function ReservarCitaPage() {
  const { usuario } = useAuth();
  const administrativa = [ROLES.ADMINISTRADOR, ROLES.RECEPCIONISTA].includes(usuario.rol);
  const location = useLocation();
  const consultiva = Boolean(matchPath('/consultar-disponibilidad', location.pathname));
  const destinoReserva = administrativa ? '/recepcion/citas/nueva' : '/reservar-cita';
  const flujo = useReservaWorkspace();
  const { registro, blocker } = flujo;
  const [paciente, setPaciente] = flujo.paciente;
  const [especialidad, setEspecialidad] = flujo.especialidad;
  const [medico, setMedico] = flujo.medico;
  const [fecha, setFecha] = flujo.fecha;
  const [errorFecha, setErrorFecha] = flujo.errorFecha;
  const [criterios, setCriterios] = flujo.criterios;
  const [revision, setRevision] = flujo.revision;
  const [epoch] = flujo.epoch;
  const fechaRef = useRef(null);
  const clearMedico = () => { setMedico(null); setCriterios(null); };
  const clearEspecialidad = () => { setEspecialidad(null); clearMedico(); };
  const clearPaciente = () => { setPaciente(null); clearEspecialidad(); };
  const cambioInterno = Boolean(blocker.location && ['/consultar-disponibilidad', destinoReserva].some(path => matchPath(path, blocker.location.pathname)));
  return <section className="workspace-page reserva-page booking-page">
    <PageHeading title={consultiva ? 'Buscar médicos y consultar disponibilidad' : 'Programar o reservar una cita presencial'} description={consultiva
      ? 'Consulta Médicos, horarios y turnos presenciales. Esta consulta no registra una cita.'
      : 'Selecciona un turno, revisa los datos y registra la cita presencial. Los horarios se consultan en America/La_Paz.'} />
    {!consultiva && <WorkflowProgress label="Etapas de la reserva" steps={etapas} current={registro.status === 'busqueda' ? 'busqueda' : ['registrada', 'incierto', 'consultando', 'ausente'].includes(registro.status) ? 'resultado' : 'revision'} />}
    {blocker.state === 'blocked' && <Alert variant="warning"><p>{cambioInterno
      ? 'Hay una revisión o comprobación de reserva pendiente. Cambiar de opción la conserva en memoria; vuelve a reserva para continuar. No se enviará otra reserva automáticamente.'
      : registro.peligro ? 'La cita podría haberse registrado. Si sales perderás esta comprobación; no se enviará otra reserva automáticamente.'
      : 'Hay una revisión de reserva pendiente. Si sales perderás la revisión; no se registrará una cita automáticamente.'}</p>
      <ActionGroup><Button variant="secondary" onClick={() => blocker.reset()}>Permanecer y consultar</Button>
      <Button variant="quiet" onClick={() => blocker.proceed()}>{cambioInterno ? 'Cambiar de opción conservando la reserva' : 'Salir sin conservar la comprobación'}</Button></ActionGroup></Alert>}
    {consultiva && registro.status !== 'busqueda' ? <Alert variant="info"><p>Se conserva el proceso de reserva anterior. Regresa para revisar su estado antes de iniciar otra selección.</p>
      <Link className="button button--secondary" to={destinoReserva}>Volver a la reserva pendiente</Link></Alert>
    : !consultiva && registro.status !== 'busqueda' ? <ReservaRegistro registro={registro} administrativa={administrativa} onVolver={() => {
      registro.reset(); setCriterios(null); setRevision(n => n + 1);
    }} /> : <div className="booking-search">
    <p className="reserva-guidance">{administrativa && !paciente ? 'Comienza seleccionando la ficha del Paciente.' : !especialidad ? 'Selecciona la especialidad para consultar sus Médicos.' : !medico ? 'Selecciona un Médico para consultar una fecha de atención.' : consultiva ? 'Elige una fecha y consulta los turnos disponibles.' : 'Elige una fecha, consulta los turnos y revisa tu selección antes de registrar.'}{!administrativa && ' La consulta y la reserva son únicamente para tu propia ficha.'}</p>
    {administrativa && <>
      <ReservaCatalogo key={`pacientes:${epoch}`} titulo="Seleccionar Paciente" tituloSeleccionado="Paciente" etiqueta="pacientes" load={listarPacientes} accionCambio="Cambiar paciente" prefijoSeleccion="Paciente seleccionado"
        campos={pacienteCampos} seleccionado={paciente} identificador="id_paciente" onInvalidate={clearPaciente}
        describir={p => `${p.nombres} ${p.apellidos} · ${p.codigo_paciente} · ${p.documento_identidad || 'Sin CI'} · Ficha ${p.activo ? 'activa' : 'inactiva'}`}
        onSelect={p => { clearEspecialidad(); setPaciente(p); }} />
      {paciente && !paciente.activo && <div className="booking-patient-warning"><Alert variant="warning"><p>La ficha seleccionada está inactiva. No permite nuevas reservas; su estado se volverá a comprobar al consultar disponibilidad.</p></Alert></div>}
    </>}
    {(!administrativa || paciente) && <>
      <ReservaCatalogo key={`especialidades:${paciente?.id_paciente ?? 'propia'}:${epoch}`} titulo="Seleccionar especialidad" etiqueta="especialidades"
        accionCambio="Cambiar especialidad" tituloSeleccionado="Especialidad"
        load={listarEspecialidadesReserva} campos={especialidadCampos} seleccionado={especialidad} identificador="id_especialidad"
        onInvalidate={clearEspecialidad} onSelect={s => { clearMedico(); setEspecialidad(s); }} describir={s => s.nombre} />
      {especialidad && <ReservaCatalogo key={`medicos:${especialidad.id_especialidad}:${paciente?.id_paciente ?? 'propia'}:${epoch}`}
        titulo={`Seleccionar Médico de ${especialidad.nombre}`} tituloSeleccionado="Médico" etiqueta="médicos" load={listarMedicosReserva} accionCambio="Cambiar médico"
        base={{ id_especialidad: especialidad.id_especialidad }} campos={medicoCampos} seleccionado={medico} identificador="id_medico"
        onInvalidate={clearMedico} onSelect={m => { setMedico(m); setCriterios(null); }}
        describir={m => `${m.nombres} ${m.apellidos} · ${m.codigo_medico} · ${m.consultorio.codigo_consultorio} — ${m.consultorio.nombre}`} />}
      {medico && <ReservaHorarioMedico horarios={medico.horarios} />}
      {medico && <form className="reserva-section reserva-filters" aria-label="Consultar disponibilidad" noValidate onSubmit={e => {
        e.preventDefault(); setCriterios(null);
        if (!fechaValida(fecha)) { setErrorFecha('Selecciona una fecha válida.'); fechaRef.current?.focus(); return; }
        setErrorFecha(''); setRevision(n => n + 1);
        setCriterios({ id_especialidad: especialidad.id_especialidad, id_medico: medico.id_medico, fecha,
          ...(administrativa ? { id_paciente: paciente.id_paciente } : {}) });
      }}>
        <h2>Elegir fecha</h2>
        <FormField ref={fechaRef} label="Fecha de atención" type="date" value={fecha} error={errorFecha}
          onChange={e => { setFecha(e.target.value); setCriterios(null); setErrorFecha(''); }} />
        <Button type="submit">Consultar turnos</Button>
        <p>Hora local de la sede. El servidor comprueba que el turno no haya comenzado.</p>
      </form>}
      {criterios && <DisponibilidadTurnos key={`${consultiva}:${JSON.stringify(criterios)}:${revision}`} criterios={criterios} consultiva={consultiva} onRevisar={consultiva ? undefined : registro.proponer} />}
      {consultiva && <section className="reserva-section"><h2>Continuar a reserva</h2><p>Se conservarán solo tus criterios y selecciones en memoria. Consulta nuevamente la disponibilidad y selecciona un turno antes de revisar la cita.</p>
        <ActionGroup><Link className="button button--secondary" to={destinoReserva}>{administrativa ? 'Programar cita' : 'Reservar cita'}</Link></ActionGroup></section>}
    </>}
    </div>}
  </section>;
}
