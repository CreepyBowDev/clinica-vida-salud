import { useEffect, useRef, useState } from 'react';
import Button from '../../../components/Button';
import Pagination from '../../../components/Pagination';
import QueryMessage from '../../../components/QueryMessage';
import Alert from '../../../components/Alert';
import { ROLES } from '../../../constants/roles';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import { consultarSolicitud, listarSolicitudes } from '../services/laboratorio.service';
import { esDestinoDocumento } from '../services/documentoLaboratorio.service';
import { useLaboratorioQuery } from '../hooks/useLaboratorioQuery';
import LaboratorioQuery from './LaboratorioQuery';

export default function SeleccionarDestinoDocumento({ origen, onSeleccionar, onCancel }) {
  const [paciente, setPaciente] = useState(null);
  const heading = useRef(null);
  useEffect(() => { heading.current?.focus(); }, [paciente]);
  return <section className="lab-destination" aria-label="Seleccionar destino de la corrección">
    <h2 ref={heading} tabIndex={-1}>Corregir asociación: seleccionar destino</h2>
    <Button variant="quiet" onClick={onCancel}>Volver al formulario sin cambiar el destino</Button>
    {paciente ? <><p>Paciente seleccionado: <strong>{paciente.nombres} {paciente.apellidos}</strong> — {paciente.codigo_paciente}</p>
      <Button variant="secondary" onClick={() => setPaciente(null)}>Seleccionar otro paciente de destino</Button>
      <SolicitudesDestino key={paciente.id_paciente} paciente={paciente} origen={origen} onSeleccionar={onSeleccionar} /></>
      : <PatientsPage laboratorio onSeleccionar={setPaciente} />}
  </section>;
}
function SolicitudesDestino({ paciente, origen, onSeleccionar }) {
  const [page, setPage] = useState(1), [seleccion, setSeleccion] = useState(null);
  const query = useLaboratorioQuery(listarSolicitudes, { rol: ROLES.ADMINISTRADOR, paciente: paciente.id_paciente, estado: 'Pendiente', page });
  if (seleccion) return <><Button variant="quiet" onClick={() => setSeleccion(null)}>Elegir otra solicitud de destino</Button><ComprobarDestino key={seleccion} id={seleccion} paciente={paciente} origen={origen} onSeleccionar={onSeleccionar} /></>;
  return <LaboratorioQuery query={query}>{d => <>
    <h2>Solicitudes Pendientes del paciente de destino</h2><p>El estado Pendiente no basta: se comprobará que la solicitud no tenga documentos ni versiones.</p>
    {d.solicitudes.length ? <ul className="lab-versions">{d.solicitudes.map(s => <li key={s.id_solicitud}><h3>{s.estudio_solicitado}</h3><p>Médico responsable: {s.medico.nombres} {s.medico.apellidos}</p>
      <Button variant="secondary" disabled={s.id_solicitud === origen.id_solicitud} onClick={() => setSeleccion(s.id_solicitud)}>Comprobar destino {s.id_solicitud}</Button></li>)}</ul> : <QueryMessage title="No hay solicitudes Pendientes para este paciente" />}
    <Pagination meta={d.meta} label="solicitudes de destino" onPage={setPage} />
  </>}</LaboratorioQuery>;
}
function ComprobarDestino({ id, paciente, origen, onSeleccionar }) {
  const query = useLaboratorioQuery(consultarSolicitud, { id, rol: ROLES.ADMINISTRADOR, paraDocumentar: true });
  return <LaboratorioQuery query={query}>{d => <>
    <h2>{d.estudio_solicitado}</h2><p>{d.paciente.nombres} {d.paciente.apellidos} — {d.paciente.codigo_paciente}</p><p>Médico responsable: {d.medico.nombres} {d.medico.apellidos}</p>
    {d.paciente.id_paciente === paciente.id_paciente && esDestinoDocumento(d, origen) ? <><p>Solicitud Pendiente, sin documento ni versiones según esta consulta. Laravel volverá a comprobarla al guardar.</p>
      <Button onClick={() => onSeleccionar(d)}>Usar esta solicitud como destino</Button></> : <Alert><p>Este destino no está disponible para la corrección. Selecciona otra solicitud Pendiente sin documentos ni versiones.</p></Alert>}
  </>}</LaboratorioQuery>;
}
