import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import PageHeading from '../../../components/PageHeading';
import FormField from '../../../components/FormField';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import CatalogResults from '../../../components/CatalogResults';
import { esFechaCivil, formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { useRecordatoriosQuery } from '../hooks/useRecordatoriosQuery';
import RecordatoriosTable from '../components/RecordatoriosTable';
import DispositivosPush from '../components/DispositivosPush';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import '../../agenda/styles/agenda.css';

const empty = { fecha_desde: '', fecha_hasta: '' };
export default function RecordatoriosPage() {
  const { status, usuario } = useAuth();
  if (status !== 'authenticated' || ![ROLES.PACIENTE, ROLES.ADMINISTRADOR].includes(usuario?.rol)) return null;
  if (usuario.rol === ROLES.ADMINISTRADOR) return <ConsultaAdministrativa key={`${usuario.id_usuario}:${usuario.rol}`} />;
  return <Listado key={`${usuario.id_usuario}:${usuario.rol}`} />;
}
function ConsultaAdministrativa() {
  const [paciente, setPaciente] = useState(null), [revision, setRevision] = useState(0);
  useEffect(() => {
    const clear = () => { setPaciente(null); setRevision(v => v + 1); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  if (!paciente) return <PatientsPage key={revision} onSeleccionar={setPaciente}
    tituloSeleccion="Seleccionar paciente para consultar recordatorios"
    descripcionSeleccion="Busca al paciente y consulta sus avisos vigentes. Incluye fichas inactivas o sin cuenta web; esta consulta no modifica sus preferencias ni dispositivos." />;
  return <Listado key={paciente.id_paciente} paciente={paciente} onCambiar={() => setPaciente(null)} />;
}
function Listado({ paciente, onCambiar }) {
  const [draft, setDraft] = useState(empty), [filters, setFilters] = useState({ ...empty, page: 1 }), [error, setError] = useState('');
  const results = useRef(null), query = useRecordatoriosQuery(paciente ? { ...filters, id_paciente: paciente.id_paciente } : filters);
  const field = name => ({ value: draft[name], onChange: e => setDraft(v => ({ ...v, [name]: e.target.value })) });
  function submit(e) {
    e.preventDefault();
    if (Object.values(draft).some(v => v && !esFechaCivil(v)) || (draft.fecha_desde && draft.fecha_hasta && draft.fecha_desde > draft.fecha_hasta)) {
      setError('La fecha final debe ser igual o posterior a la inicial y ambas deben ser válidas.'); return;
    }
    setError(''); setFilters({ ...draft, page: 1 }); query.reload(); results.current?.focus();
  }
  return <section className="workspace-page">
    <PageHeading title={paciente ? 'Recordatorios del paciente' : 'Mis recordatorios'} description={paciente
      ? 'Consulta administrativa de avisos vigentes. Fechas y horarios de la sede: America/La_Paz.'
      : 'Consulta los avisos vigentes de tus citas. Fechas y horarios de la sede: America/La_Paz.'} />
    {paciente && <><p>Paciente seleccionado: <strong>{paciente.nombres} {paciente.apellidos}</strong> · {paciente.codigo_paciente}</p>
      <ActionGroup><Button variant="secondary" onClick={onCambiar}>Cambiar paciente</Button></ActionGroup></>}
    <p>Los avisos se generan automáticamente. Consultar o actualizar esta lista no genera avisos ni los marca como leídos.</p>
    {!paciente && <DispositivosPush />}
    <form className="catalog-filters" aria-label="Filtrar recordatorios" onSubmit={submit}>
      <FormField label="Desde" type="date" {...field('fecha_desde')} /><FormField label="Hasta" type="date" {...field('fecha_hasta')} />
      <ActionGroup><Button type="submit" disabled={query.remaining > 0}>Consultar</Button>
        <Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft(empty); setError(''); setFilters({ ...empty, page: 1 }); query.reload(); }}>Limpiar filtros</Button></ActionGroup>
      {error && <p role="alert">{error}</p>}
    </form>
    <ActionGroup><Button variant="secondary" disabled={query.loading || query.remaining > 0} onClick={query.reload}>Actualizar recordatorios</Button></ActionGroup>
    <div ref={results} tabIndex={-1} role="region" aria-label="Resultados de recordatorios" aria-busy={query.loading}>
      <CatalogResults query={query} label="recordatorios" loadingLabel="Consultando recordatorios" emptyTitle="No hay recordatorios para estos filtros"
        emptyHint={paciente ? 'Prueba otras fechas o vuelve a consultar más tarde. Esta lista no sustituye el historial de citas del paciente.' : 'Prueba otras fechas o vuelve a consultar más tarde. Esta lista no sustituye tu historial de citas.'}
        onPage={page => { setFilters(v => ({ ...v, page })); results.current?.focus(); }}>
        {query.data && <><p>Consulta realizada: <time dateTime={query.data.meta.consultado_en}>{formatearInstanteCita(query.data.meta.consultado_en)}</time>. Actualiza para comprobar cambios.</p>
          <RecordatoriosTable avisos={query.data.data} administrativo={Boolean(paciente)} /></>}
      </CatalogResults>
    </div>
  </section>;
}
