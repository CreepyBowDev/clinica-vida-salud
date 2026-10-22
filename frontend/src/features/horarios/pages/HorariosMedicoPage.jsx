import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import SelectField from '../../../components/SelectField';
import CatalogResults from '../../../components/CatalogResults';
import PageHeading from '../../../components/PageHeading';
import ActionGroup from '../../../components/ActionGroup';
import { useCatalogQuery } from '../../../hooks/useCatalogQuery';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { consultarMedico } from '../../medicos/services/medico.service';
import { consultarAsignacion } from '../../consultorios/services/asignacionConsultorio.service';
import { listarHorarios } from '../services/horario.service';
import { diaSemana, horaLocal } from '../utils/horarioFormat';
import HorarioEditor from '../components/HorarioEditor';
import '../../../styles/catalogs.css';
import '../styles/horarios.css';

const contextFields = [], listFields = ['activo', 'page', 'per_page'];
async function contexto(id, signal) { const [medico, asignacion] = await Promise.all([consultarMedico(id, signal), consultarAsignacion(id, signal)]); return { medico, asignacion }; }
export default function HorariosMedicoPage() {
  const { id } = useParams();
  if (!esIdentificador(id)) return <section className="page-message"><h1>Médico no disponible</h1><Link to="/administracion/horarios">Volver a horarios</Link></section>;
  return <Load key={id} id={id} />;
}
function Load({ id }) {
  const query = useCatalogQuery(contexto, id, contextFields);
  return <section className="workspace-page catalog-page"><Link className="back-link" to="/administracion/horarios">Volver a horarios</Link><PageHeading title="Horarios del Médico" description="Intervalos semanales y cobertura de atención de la sede." />
    {query.loading ? <p role="status">Consultando médico y consultorio…</p> : query.failure ? <><Alert>{query.failure.status === 404 ? 'No se encontró el médico solicitado.' : query.failure.message}</Alert><Button disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></>
      : <Schedule id={id} context={query.data} refreshContext={query.reload} />}
  </section>;
}
function Schedule({ id, context, refreshContext }) {
  const [filters, setFilters] = useState({ id, activo: '', page: 1, per_page: 20 });
  const [editor, setEditor] = useState(null), [notice, setNotice] = useState('');
  const query = useCatalogQuery(listarHorarios, filters, listFields);
  // CatalogResults pagina data[]; la API de horarios coloca sus filas en data.elementos.
  const catalog = { ...query, data: query.data ? { ...query.data, data: query.data.data.elementos } : undefined };
  const sala = context.asignacion.consultorio;
  const close = () => { setEditor(null); query.reload(); };
  return <><div className="guided-context"><h2>{context.medico.nombres} {context.medico.apellidos}</h2>
    <p>{context.medico.activo ? 'Médico activo' : 'Médico inactivo'} · {context.medico.codigo_medico}</p>
    <p>Consultorio fijo: {sala ? `${sala.codigo_consultorio} — ${sala.nombre}${sala.activo ? '' : ' (inactivo)'}` : 'Sin asignación'}</p>
    <p className="catalog-note">Los horarios activos reservan su franja aunque el Médico esté inactivo. Reactivar al Médico no reactiva horarios. Desactivar un horario no cancela ni mueve citas.</p>
    <ActionGroup className="form-actions"><Link to={`/administracion/medicos/${id}/consultorio`}>Gestionar consultorio fijo</Link><Button variant="quiet" disabled={Boolean(editor)} onClick={refreshContext}>Consultar médico y consultorio actuales</Button></ActionGroup>
  </div>
    {notice && <Alert variant="success">{notice}</Alert>}
    <div className="catalog-filters horario-filters"><SelectField label="Estado de horario" disabled={Boolean(editor)} value={filters.activo} error={query.failure?.fields.activo} onChange={event => setFilters({ ...filters, activo: event.target.value, page: 1 })}><option value="">Todos los horarios</option><option value="1">Activos</option><option value="0">Inactivos</option></SelectField>
      <SelectField label="Horarios por página" disabled={Boolean(editor)} value={filters.per_page} error={query.failure?.fields.per_page} onChange={event => setFilters({ ...filters, per_page: Number(event.target.value), page: 1 })}>{[10, 20, 50, 100].map(n => <option key={n} value={n}>{n}</option>)}</SelectField>
    </div>
    {query.data && !query.loading && !query.failure && <p className="catalog-note">Duración vigente: {query.data.data.duracion_turno_minutos} minutos. La capacidad indicada es de turnos completos, no disponibilidad para reservar. Las citas existentes conservan su duración.</p>}
    {editor ? <HorarioEditor key={`${editor.accion}-${editor.initial?.id_horario || 'nuevo'}`} id={id} version={editor.version} initial={editor.initial} accion={editor.accion} onClose={close} onSaved={() => { setNotice('Horario guardado. Se consultó nuevamente el listado.'); close(); }} />
      : <CatalogResults query={catalog} label="horarios" loadingLabel="Consultando horarios…" emptyTitle="No hay horarios para estos filtros" emptyHint="Incluye otros estados o registra un intervalo de atención." onPage={page => setFilters({ ...filters, page })}>
        <div className="catalog-table-wrap query-table"><table className="catalog-table" role="table"><caption>Horarios semanales, ordenados por día y hora de inicio</caption><thead role="rowgroup"><tr role="row">{['Día e intervalo', 'Estado', 'Capacidad', 'Acciones'].map(label => <th key={label} scope="col" role="columnheader">{label}</th>)}</tr></thead>
          <tbody role="rowgroup">{query.data?.data.elementos.map(h => <tr role="row" key={h.id_horario}><td role="cell"><strong>{diaSemana(h.dia_semana)}</strong><p>{horaLocal(h.hora_inicio)} – {horaLocal(h.hora_fin)}</p></td>
            <td role="cell"><span className="catalog-cell-label" aria-hidden="true">Estado</span>{h.activo ? 'Activo' : 'Inactivo'}</td><td role="cell"><span className="catalog-cell-label" aria-hidden="true">Capacidad</span>{h.turnos_completos} turnos completos</td>
            <td role="cell" className="query-table-action"><div className="horario-row-actions"><Button variant="secondary" onClick={() => { setNotice(''); setEditor({ accion: 'EDITAR', initial: h, version: query.data.data.actualizado_en }); }} aria-label={`Editar horario ${h.id_horario}`}>Editar</Button>
              <Button variant="quiet" onClick={() => { setNotice(''); setEditor({ accion: 'ESTADO', initial: h, version: query.data.data.actualizado_en }); }} aria-label={`${h.activo ? 'Desactivar' : 'Reactivar'} horario ${h.id_horario}`}>{h.activo ? 'Desactivar' : 'Reactivar'}</Button></div></td></tr>)}</tbody></table></div>
      </CatalogResults>}
    {!editor && <ActionGroup className="form-actions"><Button disabled={query.loading || Boolean(query.failure)} onClick={() => { setNotice(''); setEditor({ accion: 'CREAR', version: query.data.data.actualizado_en }); }}>Registrar horario</Button></ActionGroup>}
  </>;
}
