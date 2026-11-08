import { useEffect, useRef, useState } from 'react';
import { Link, matchPath, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { escucharCambioSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { paymentPresentation } from '../../../constants/paymentPresentation';
import PageHeading from '../../../components/PageHeading';
import Button from '../../../components/Button';
import FormField from '../../../components/FormField';
import SelectField from '../../../components/SelectField';
import ActionGroup from '../../../components/ActionGroup';
import CatalogResults from '../../../components/CatalogResults';
import QueryMessage from '../../../components/QueryMessage';
import Alert from '../../../components/Alert';
import Pagination from '../../../components/Pagination';
import PatientsPage from '../../pacientes/pages/PatientsPage';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esFechaCivil, formatearInstanteCita } from '../../agenda/utils/agendaFormat';
import { consultarPagos } from '../services/pago.service';
import { usePagoQuery } from '../hooks/usePagoQuery';
import { esUuid, ESTADOS_INTENTO, PROVEEDORES, situacionObligacion } from '../utils/pagoFormat';
import PrecioPago from '../components/PrecioPago';
import HistorialPagos, { DevolucionPago } from '../components/HistorialPagos';
import OperacionPago from '../components/OperacionPago';
import DescargarRecibo from '../components/DescargarRecibo';
import PagarPage from './PagarPage';
import '../../../styles/catalogs.css';
import '../styles/pagos.css';

export default function PagosPage() {
  const { status, usuario } = useAuth(), params = useParams(), navigate = useNavigate(), location = useLocation();
  const [epoch, setEpoch] = useState(0), [paused, setPaused] = useState(false);
  useEffect(() => {
    const clear = () => { setPaused(true); setEpoch(n => n + 1); };
    const unsubscribe = escucharCambioSesion(clear); window.addEventListener('pagehide', clear);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); };
  }, []);
  if (status !== 'authenticated' || ![ROLES.PACIENTE, ROLES.ADMINISTRADOR].includes(usuario?.rol)) return null;
  if (paused) return <section className="workspace-page"><QueryMessage title="El contexto cambió" description="Los datos financieros se retiraron. Consulta nuevamente antes de continuar." />
    <Button onClick={() => { setPaused(false); navigate('/pagos'); }}>Volver a consultar pagos</Button></section>;
  const admin = usuario.rol === ROLES.ADMINISTRADOR;
  const entry = paymentPresentation(location.pathname, location.search, usuario.rol);
  if (!entry.valid && !params.id) return <VistaNoDisponible />;
  if (admin && !params.pacienteId) return <PatientsPage key={`${usuario.id_usuario}:${epoch}`} onSeleccionar={p => navigate(`/pagos/pacientes/${p.id_paciente}${entry.search}`)}
    tituloSeleccion="Seleccionar paciente para consultar pagos" descripcionSeleccion="Consulta obligaciones e historial financiero en solo lectura. No permite pagar por el paciente ni resolver incidencias." />;
  if ((!admin && params.pacienteId) || (admin && !esIdentificador(params.pacienteId)) || (params.id && !esUuid(params.id))) return <QueryMessage title="Consulta no disponible" description="Revisa el recurso y el paciente seleccionado." />;
  const scope = { rol: usuario.rol, ...(admin ? { id_paciente: params.pacienteId } : {}) };
  const base = admin ? `/pagos/pacientes/${params.pacienteId}` : '/pagos';
  const key = `${usuario.id_usuario}:${usuario.rol}:${params.pacienteId ?? 'propio'}:${params.id ?? 'lista'}:${epoch}`;
  return params.id ? <Detalle key={key} scope={scope} id={params.id} base={base} entry={entry} /> : <Listado key={key} scope={scope} base={base} entry={entry} />;
}
function VistaNoDisponible() {
  return <section className="workspace-page"><QueryMessage title="Vista de pagos no disponible" description="Revisa la opción solicitada antes de continuar." /><Link to="/pagos">Volver a pagos</Link></section>;
}
function Listado({ scope, base, entry }) {
  const navigate = useNavigate(), tipo = entry.value === 'historial' ? 'historial' : 'obligaciones';
  const indicadores = [['exigible', 'Exigible'], ['liquidada', 'Liquidada'], ['vencida', 'Vencida regularizable'], ['operacion_en_verificacion', 'En verificación']];
  const [draft, setDraft] = useState({}), [filters, setFilters] = useState({ page: 1, per_page: 20 }), [error, setError] = useState('');
  useEffect(() => { setDraft({}); setFilters({ page: 1, per_page: 20 }); setError(''); }, [tipo]);
  const query = usePagoQuery(consultarPagos, { ...scope, ...filters, tipo });
  const detallePath = id => `${base}/obligaciones/${id}${entry.search}`;
  const aplicados = [filters.fecha_desde && `Desde ${filters.fecha_desde}`, filters.fecha_hasta && `Hasta ${filters.fecha_hasta}`,
    ...indicadores.map(([k, label]) => filters[k] ? `${label}: ${filters[k] === '1' ? 'Sí' : 'No'}` : null),
    filters.proveedor && `Proveedor: ${PROVEEDORES[filters.proveedor]}`, filters.resultado && `Resultado: ${filters.resultado}`].filter(Boolean);
  const field = name => ({ value: draft[name] ?? '', onChange: e => setDraft(v => ({ ...v, [name]: e.target.value })) });
  function submit(e) {
    e.preventDefault();
    if (['fecha_desde', 'fecha_hasta'].some(k => draft[k] && !esFechaCivil(draft[k])) || (draft.fecha_desde && draft.fecha_hasta && draft.fecha_desde > draft.fecha_hasta)) {
      setError('Revisa las fechas; la final no puede ser anterior a la inicial.'); return;
    }
    setError(''); setFilters({ ...draft, page: 1, per_page: Number(draft.per_page || 20) }); query.reload();
  }
  return <section className="workspace-page catalog-page payments-page payment-list">
    <PageHeading title={entry.value ? entry.label : scope.id_paciente ? 'Pagos del paciente seleccionado' : 'Mis pagos'} description={entry.value === 'pagar' ? 'Selecciona una obligación propia para consultar contexto y capacidades. Seleccionar no inicia un intento ni garantiza que el pago esté habilitado.' : 'Obligaciones e intentos de pruebas académicas. Sin dinero real. La situación financiera no bloquea tu atención.'} />
    <div className="payment-scope" aria-label="Ámbito de la consulta">
      <div><strong>{scope.id_paciente ? `Ficha seleccionada: ${scope.id_paciente}` : 'Consulta de tus propias obligaciones e intentos'}</strong>
        <p>{scope.id_paciente ? 'Solo lectura. No permite pagar por el paciente ni resolver incidencias.' : 'Los resultados corresponden a tu ficha de Paciente.'}</p></div>
      {scope.id_paciente && <Link to={`/pagos${entry.search}`}>Cambiar paciente</Link>}
    </div>
    <ActionGroup>{(scope.id_paciente ? ['obligaciones', 'historial'] : ['obligaciones', 'pagar', 'historial']).map(t => <Button key={t} variant={(entry.value ?? 'obligaciones') === t ? 'primary' : 'secondary'} aria-pressed={(entry.value ?? 'obligaciones') === t}
      disabled={query.remaining > 0} onClick={() => navigate(`${base}?vista=${t}`)}>{t === 'obligaciones' ? 'Obligaciones' : t === 'pagar' ? 'Pagar una cita' : 'Historial de intentos'}</Button>)}</ActionGroup>
    <details className="payment-filters">
      <summary>Filtros <span>{aplicados.length ? `${aplicados.length} criterios aplicados` : 'Sin restricciones de búsqueda'}</span></summary>
    <form className="catalog-filters" aria-label="Filtrar pagos" onSubmit={submit}>
      <FormField label="Desde" type="date" hint={tipo === 'obligaciones' ? 'Fecha de emisión de la obligación.' : 'Fecha de creación del intento.'} {...field('fecha_desde')} />
      <FormField label="Hasta" type="date" {...field('fecha_hasta')} />
      {tipo === 'obligaciones' ? indicadores.map(([name, label]) =>
        <SelectField key={name} label={label} {...field(name)}><option value="">Todas</option><option value="1">Sí</option><option value="0">No</option></SelectField>) : <>
        <SelectField label="Proveedor" {...field('proveedor')}><option value="">Todos</option>{Object.entries(PROVEEDORES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</SelectField>
        <SelectField label="Resultado del intento" {...field('resultado')}><option value="">Todos</option>{ESTADOS_INTENTO.map(v => <option key={v}>{v}</option>)}</SelectField>
      </>}
      <SelectField label="Resultados por página" value={draft.per_page ?? '20'} onChange={e => setDraft(v => ({ ...v, per_page: e.target.value }))}>
        {[10, 20, 50, 100].map(n => <option key={n}>{n}</option>)}</SelectField>
      <ActionGroup><Button type="submit" disabled={query.loading || query.remaining > 0}>Consultar pagos</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft({}); setFilters({ page: 1, per_page: 20 }); setError(''); query.reload(); }}>Restablecer filtros</Button></ActionGroup>
      {error && <p role="alert">{error}</p>}
    </form>
    </details>
    <p className="payment-filter-summary" role="status">{aplicados.length ? `Filtros aplicados: ${aplicados.join('; ')}.` : 'Sin filtros aplicados.'} {filters.per_page} resultados por página.</p>
    <p className="catalog-note payment-list-note">Las citas sin precio no son deudas de importe cero. Consulta {scope.id_paciente ? <Link to={`/recepcion/pacientes/${scope.id_paciente}/citas`}>el historial de citas</Link> : <Link to="/mis-citas">Mis citas</Link>} para distinguirlas de las citas antiguas no incorporadas.</p>
    <div className="payment-results" aria-busy={query.loading}>
    <CatalogResults query={query} label="pagos" loadingLabel="Consultando pagos…" emptyTitle="No hay registros para estos filtros" emptyHint="Prueba otros filtros. Una lista vacía no acredita que una operación incierta haya fallado."
      onPage={page => setFilters(v => ({ ...v, page }))}>
      {tipo === 'historial' ? <HistorialPagos intentos={query.data?.data ?? []} detallePath={detallePath} /> :
        <div className="catalog-table-wrap payment-records"><table className="catalog-table" role="table"><caption>Obligaciones por fecha de emisión descendente</caption><thead><tr>
          <th scope="col">Cita</th><th scope="col">Precio histórico</th><th scope="col">Situación financiera</th></tr></thead>
           <tbody>{query.data?.data.map(o => <tr key={o.id}><td><Link className="payment-record-title" to={detallePath(o.id)}>{entry.value === 'pagar' ? `Revisar para pagar: ${o.cita.codigo_cita}` : o.cita.codigo_cita}</Link><p>Inicio: {formatearInstanteCita(o.cita.inicio_at)}</p><p className="payment-record-meta">Estado de cita: {o.cita.estado}</p></td>
             <td><PrecioPago precio={o.precio} /></td>
             <td><span className="catalog-cell-label">Situación financiera</span><strong className="payment-situation">{situacionObligacion(o)}</strong>
               {o.origen_liquidacion && <p>Origen del pago: {o.origen_liquidacion === 'Simulado' ? 'Simulación académica' : o.origen_liquidacion}</p>}
               {o.operacion_en_verificacion && <p>Operación en verificación. Consulta el detalle antes de continuar.</p>}</td></tr>)}</tbody></table></div>}
    </CatalogResults>
    </div>
  </section>;
}
function Detalle({ scope, id, base, entry }) {
  const location = useLocation();
  const academic = Boolean(matchPath('/pagos/obligaciones/:id/pagar', location.pathname));
  const [pages, setPages] = useState({ intentos_page: 1, devoluciones_page: 1 });
  const [risk, setRisk] = useState(false);
  const query = usePagoQuery(consultarPagos, { ...scope, ...pages, tipo: 'detalle', id }, !academic), o = query.data;
  const operational = entry.valid && scope.rol === ROLES.PACIENTE && (!entry.value || entry.value === 'pagar');
  // El contexto del único escritor vive fuera de la lectura/carga y no depende de search.
  // Un proceso protegido conserva su revisión, respuesta y clave en el hook original.
  const [writer, setWriter] = useState(null);
  const [writerMode, setWriterMode] = useState(academic && entry.valid ? 'academic' : null);
  const requestedMode = entry.valid && academic ? 'academic' : operational ? 'external' : null;
  const previousMode = useRef(requestedMode);
  useEffect(() => {
    if (risk) return;
    if (previousMode.current !== requestedMode && requestedMode === 'external') query.reload();
    previousMode.current = requestedMode;
  }, [requestedMode, risk]);
  useEffect(() => { if (!risk) setWriterMode(requestedMode); }, [risk, requestedMode]);
  useEffect(() => {
    if (risk) return;
    if (!operational || writerMode !== 'external') setWriter(null);
    else if (o) setWriter(o.intento_actual?.proveedor !== 'simulado' && (!entry.value || o.puede_iniciar_pago || o.intento_actual) ? o : null);
  }, [o, operational, entry.value, risk, writerMode]);
  const suspended = !operational || (writerMode && requestedMode !== writerMode);
  const canSelectPayment = o && (o.puede_iniciar_pago || o.intento_actual || (o.simulacion?.habilitada && o.simulacion.puede_iniciar));
  return <section className="workspace-page catalog-page payments-page">
    {!academic && <Link className="back-link" to={`${base}${entry.valid ? entry.search : ''}`}>Volver a obligaciones e historial</Link>}
    {!entry.valid && <VistaNoDisponible />}
    {entry.valid && !academic && <PageHeading title={entry.value ? entry.label : 'Detalle de obligación'} description="Importes históricos y resultados registrados por el servidor. Pagar no confirma llegada ni atención." />}
    {suspended && risk && <Alert variant="warning"><p>Hay un proceso de pago conservado para esta obligación. Resuélvelo antes de preparar otro. No se repetirá ningún envío.</p><Link to={`${base}/obligaciones/${id}${writerMode === 'academic' ? '/pagar' : ''}?vista=pagar`}>Volver al proceso conservado</Link></Alert>}
    {entry.valid && !academic && (query.loading ? <QueryMessage loading title="Consultando obligación…" /> : query.failure ? <Alert><p>{query.failure.message}</p></Alert> : null)}
    {entry.valid && o && !academic &&
      <div className="payment-scope"><div><strong>{scope.rol === ROLES.ADMINISTRADOR ? `Ficha seleccionada: ${scope.id_paciente}` : 'Mis pagos · obligación propia'}</strong>
        <p>{scope.rol === ROLES.ADMINISTRADOR ? 'Consulta administrativa de solo lectura. Las operaciones de pago corresponden al titular.' : 'Precio y resultados de esta cita, según la consulta al servidor.'}</p></div></div>}
      <div className={academic && writerMode !== 'external' ? undefined : 'payment-detail-grid'} hidden={academic && writerMode !== 'external'}>
      {entry.valid && o && !academic && <section className="catalog-panel payment-detail-summary"><h2>Cita {o.cita.codigo_cita}</h2>
         <dl className="payment-detail-facts"><div><dt>Inicio de atención</dt><dd>{formatearInstanteCita(o.cita.inicio_at)}</dd></div><div><dt>Estado asistencial</dt><dd>{o.cita.estado}</dd></div></dl>
         <div className="payment-detail-situation"><h3>Situación financiera</h3><strong className="payment-situation">{situacionObligacion(o)}</strong>
         {o.operacion_en_verificacion && <Alert variant="warning"><p>Hay una operación en verificación. No inicies otro pago para despejarla.</p></Alert>}
         {o.vencida && <p>Puede regularizarse sin recargos ni bloqueo asistencial, si el servidor habilita el pago.</p>}
         {!o.exigible && <p>No permite nuevos pagos. El historial y las devoluciones se conservan aunque la cita esté cancelada.</p>}
         {o.liquidada && <p>{o.origen_liquidacion === 'Simulado' ? 'Pago académico aplicado, sin transferencia de dinero real.' : 'La obligación tiene un cobro aplicado registrado por el servidor.'}</p>}</div>
         <h3>Precio histórico de la cita</h3><PrecioPago precio={o.precio} />
          {o.recibo_academico && <DescargarRecibo id={o.id} {...scope} recibo={o.recibo_academico} />}
         <dl className="payment-detail-facts"><div><dt>Emisión</dt><dd>{formatearInstanteCita(o.emitida_at)}</dd></div><div><dt>Vencimiento financiero</dt><dd>{formatearInstanteCita(o.vencimiento_at)}</dd></div></dl>
         <p className="payment-record-meta">Consulta realizada: {o.calculado_at ? formatearInstanteCita(o.calculado_at) : 'Instante no disponible'}.</p>
        </section>}
      <div className="payment-detail-actions">
       {entry.valid && o && !academic && <>
       {operational && !risk && o.simulacion?.habilitada && (o.simulacion.puede_iniciar || o.intento_actual?.proveedor === 'simulado') && <section className="payment-academic-entry" aria-label="Pago académico">
         <h2>Pago académico simulado</h2>
         <p>Registra el resultado en esta obligación, sin enviar dinero ni contactar con proveedores.</p>
          <Link className="button button--primary" to={`/pagos/obligaciones/${o.id}/pagar${entry.search}`}>{o.intento_actual?.proveedor === 'simulado' ? 'Continuar pago académico' : 'Pagar'}</Link>
       </section>}
       {entry.value === 'obligaciones' && scope.rol === ROLES.PACIENTE && canSelectPayment && <Link className="button button--secondary" to={`${location.pathname}?vista=pagar`}>Pagar una cita</Link>}
       {operational && !writer && !canSelectPayment && <p>No hay una acción de pago disponible en esta consulta. Consulta las capacidades vigentes o selecciona otra obligación.</p>}
       {operational && o.intento_actual?.proveedor === 'simulado' && !o.simulacion?.habilitada && <p>Esta consulta no ofrece una acción académica disponible. El intento registrado se conserva en el historial; actualiza el detalle para consultar las capacidades vigentes.</p>}
       {scope.rol === ROLES.ADMINISTRADOR && <section className="payment-readonly"><h2>Consulta del titular</h2><p>Puedes revisar importes, intentos, cobros y devoluciones. No puedes pagar, capturar ni cerrar intentos en nombre del paciente.</p></section>}
       </>}
       {writerMode === 'external' && writer && <OperacionPago obligacion={writer} suspended={suspended || query.loading || query.remaining > 0 || Boolean(query.failure)} onConsultar={() => { setWriter(null); setRisk(false); query.reload(); }} onRiskChange={setRisk} secondary={Boolean(writer.simulacion?.habilitada && writer.simulacion.puede_iniciar)} />}
      </div></div>
      {entry.valid && o && !academic && <>
      <section className="payment-detail-history"><h2>Historial de intentos y cobros</h2><p className="payment-record-meta">El resultado de un intento no equivale por sí solo a un cobro aplicado. Las incidencias se muestran junto al cobro correspondiente.</p>{o.intentos.data.length ? <HistorialPagos intentos={o.intentos.data} /> : <p>No hay intentos registrados en esta página.</p>}
        <Pagination meta={o.intentos.meta} label="intentos" onPage={intentos_page => { if (!risk) setPages(v => ({ ...v, intentos_page })); }} /></section>
      <section className="payment-detail-refunds"><h2>Devoluciones</h2><p className="payment-record-meta">Se consultan por separado del pago original; no sustituyen su historial.</p>{o.devoluciones.data.length ? o.devoluciones.data.map(d => <DevolucionPago key={d.id} devolucion={d} />) : <p>No hay devoluciones registradas en esta página.</p>}
        <Pagination meta={o.devoluciones.meta} label="devoluciones" onPage={devoluciones_page => { if (!risk) setPages(v => ({ ...v, devoluciones_page })); }} />
        <p>Las incidencias y devoluciones pendientes o fallidas requieren revisión. Este portal no permite resolverlas ni marcar éxitos manuales.</p></section>
    </>}
    {writerMode === 'academic' && <PagarPage suspended={!entry.valid || !academic} onRiskChange={setRisk} />}
    {query.remaining > 0 && <p role="status">Espera {query.remaining} segundos.</p>}
    {!academic && <Button variant="secondary" disabled={risk || query.loading || query.remaining > 0} onClick={query.reload}>Actualizar detalle</Button>}
  </section>;
}
