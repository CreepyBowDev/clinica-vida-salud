import { useEffect, useRef, useState } from 'react';
import { useBlocker } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import Alert from '../../../components/Alert';
import ReservaCatalogo from '../components/ReservaCatalogo';
import DisponibilidadTurnos from '../components/DisponibilidadTurnos';
import ReservaRegistro from '../components/ReservaRegistro';
import { useReservaRegistro } from '../hooks/useReservaRegistro';
import { listarEspecialidadesReserva, listarMedicosReserva } from '../services/reserva.service';
import { listarPacientes } from '../../pacientes/services/paciente.service';
import '../../../styles/catalogs.css';
import '../styles/reserva.css';

const especialidadCampos = [{ name: 'nombre', label: 'Nombre de especialidad' }];
const medicoCampos = [{ name: 'nombre', label: 'Nombre del Médico' }, { name: 'consultorio', label: 'Código o nombre del consultorio' }];
const pacienteCampos = [{ name: 'documento_identidad', label: 'CI (si existe)', max: 50 }, { name: 'nombres', label: 'Nombres del Paciente' }, { name: 'apellidos', label: 'Apellidos del Paciente' }];
function fechaValida(value) {
  if (!/^(?!0000)\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const [y, m, d] = value.split('-').map(Number);
  const date = new Date(0); date.setUTCFullYear(y, m - 1, d);
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}
export default function ReservarCitaPage() {
  const { usuario } = useAuth();
  // Otra identidad/rol nunca hereda una selección privada de esta instancia.
  return <ReservaFlujo key={`${usuario.id_usuario}:${usuario.rol}`} recepcion={usuario.rol === ROLES.RECEPCIONISTA} />;
}
function ReservaFlujo({ recepcion }) {
  const registro = useReservaRegistro();
  const blocker = useBlocker(registro.peligro);
  useEffect(() => {
    // Si el resultado ya se conoce, no conservar una advertencia de incertidumbre anterior.
    if (!registro.peligro && blocker.state === 'blocked') blocker.reset();
  }, [registro.peligro, blocker]);
  const [paciente, setPaciente] = useState(null);
  const [especialidad, setEspecialidad] = useState(null);
  const [medico, setMedico] = useState(null);
  const [fecha, setFecha] = useState('');
  const [errorFecha, setErrorFecha] = useState('');
  const [criterios, setCriterios] = useState(null);
  const [revision, setRevision] = useState(0);
  const [epoch, setEpoch] = useState(0);
  const fechaRef = useRef(null);
  const clearMedico = () => { setMedico(null); setCriterios(null); };
  const clearEspecialidad = () => { setEspecialidad(null); clearMedico(); };
  const clearPaciente = () => { setPaciente(null); clearEspecialidad(); };
  useEffect(() => {
    const clear = () => { setPaciente(null); setEspecialidad(null); setMedico(null); setCriterios(null); setFecha(''); setEpoch(n => n + 1); };
    window.addEventListener('pagehide', clear);
    return () => window.removeEventListener('pagehide', clear);
  }, []);
  return <section className="workspace-page reserva-page">
    <header className="page-heading"><h1>{recepcion ? 'Programar cita' : 'Reservar cita'}</h1>
      <p>Busca un turno presencial en la sede de la clínica. Los horarios se consultan en America/La_Paz.</p></header>
    <p className="reserva-progress">{registro.status === 'busqueda' ? 'Buscar turno' : registro.status === 'registrada' ? 'Resultado' : 'Revisar y registrar'} · Atención presencial</p>
    {blocker.state === 'blocked' && <Alert variant="warning"><p>La cita podría haberse registrado. Si sales perderás esta comprobación; no se enviará otra reserva automáticamente.</p>
      <Button variant="secondary" onClick={() => blocker.reset()}>Permanecer y consultar</Button>
      <Button variant="quiet" onClick={() => blocker.proceed()}>Salir sin conservar la comprobación</Button></Alert>}
    {registro.status !== 'busqueda' ? <ReservaRegistro registro={registro} recepcion={recepcion} onVolver={() => {
      registro.reset(); setCriterios(null); setRevision(n => n + 1);
    }} /> : <>
    {recepcion && <>
      <ReservaCatalogo key={`pacientes:${epoch}`} titulo="Seleccionar Paciente" etiqueta="pacientes" load={listarPacientes}
        campos={pacienteCampos} seleccionado={paciente} identificador="id_paciente" onInvalidate={clearPaciente}
        describir={p => `${p.nombres} ${p.apellidos} · ${p.codigo_paciente} · ${p.documento_identidad || 'Sin CI'} · Ficha ${p.activo ? 'activa' : 'inactiva'}`}
        onSelect={p => { clearEspecialidad(); setPaciente(p); }} />
      {paciente && <div className="reserva-selection"><p>Paciente seleccionado: {paciente.nombres} {paciente.apellidos} · {paciente.codigo_paciente}</p>
        {!paciente.activo && <Alert variant="warning"><p>La ficha seleccionada está inactiva. No permite nuevas reservas; su estado se volverá a comprobar al consultar disponibilidad.</p></Alert>}</div>}
    </>}
    {(!recepcion || paciente) && <>
      <ReservaCatalogo key={`especialidades:${paciente?.id_paciente ?? 'propia'}:${epoch}`} titulo="Seleccionar especialidad" etiqueta="especialidades"
        load={listarEspecialidadesReserva} campos={especialidadCampos} seleccionado={especialidad} identificador="id_especialidad"
        onInvalidate={clearEspecialidad} onSelect={s => { clearMedico(); setEspecialidad(s); }} describir={s => s.nombre} />
      {especialidad && <ReservaCatalogo key={`medicos:${especialidad.id_especialidad}:${paciente?.id_paciente ?? 'propia'}:${epoch}`}
        titulo={`Seleccionar Médico de ${especialidad.nombre}`} etiqueta="médicos" load={listarMedicosReserva}
        base={{ id_especialidad: especialidad.id_especialidad }} campos={medicoCampos} seleccionado={medico} identificador="id_medico"
        onInvalidate={clearMedico} onSelect={m => { setMedico(m); setCriterios(null); }}
        describir={m => `${m.nombres} ${m.apellidos} · ${m.codigo_medico} · ${m.consultorio.codigo_consultorio} — ${m.consultorio.nombre}`} />}
      {medico && <form className="reserva-section reserva-filters" aria-label="Consultar disponibilidad" noValidate onSubmit={e => {
        e.preventDefault(); setCriterios(null);
        if (!fechaValida(fecha)) { setErrorFecha('Selecciona una fecha válida.'); fechaRef.current?.focus(); return; }
        setErrorFecha(''); setRevision(n => n + 1);
        setCriterios({ id_especialidad: especialidad.id_especialidad, id_medico: medico.id_medico, fecha,
          ...(recepcion ? { id_paciente: paciente.id_paciente } : {}) });
      }}>
        <FormField ref={fechaRef} label="Fecha de atención" type="date" value={fecha} error={errorFecha}
          onChange={e => { setFecha(e.target.value); setCriterios(null); setErrorFecha(''); }} />
        <p>Hora local de la sede. El servidor comprueba que el turno no haya comenzado.</p>
        <Button type="submit">Consultar turnos</Button>
      </form>}
      {criterios && <DisponibilidadTurnos key={`${JSON.stringify(criterios)}:${revision}`} criterios={criterios} onRevisar={registro.proponer} />}
    </>}
    </>}
  </section>;
}
