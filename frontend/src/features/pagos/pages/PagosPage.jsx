import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../../../hooks/useAuth';
import { escucharCambioSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
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
import { esUuid, ESTADOS_INTENTO, PROVEEDORES, decimalVisible, situacionObligacion } from '../utils/pagoFormat';
import PrecioPago from '../components/PrecioPago';
import HistorialPagos, { DevolucionPago } from '../components/HistorialPagos';
import OperacionPago from '../components/OperacionPago';
import '../../../styles/catalogs.css';
import '../styles/pagos.css';

export default function PagosPage() {
  const { status, usuario } = useAuth(), params = useParams(), navigate = useNavigate();
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
  if (admin && !params.pacienteId) return <PatientsPage key={`${usuario.id_usuario}:${epoch}`} onSeleccionar={p => navigate(`/pagos/pacientes/${p.id_paciente}`)}
    tituloSeleccion="Seleccionar paciente para consultar pagos" descripcionSeleccion="Consulta obligaciones e historial financiero en solo lectura. No permite pagar por el paciente ni resolver incidencias." />;
  if ((!admin && params.pacienteId) || (admin && !esIdentificador(params.pacienteId)) || (params.id && !esUuid(params.id))) return <QueryMessage title="Consulta no disponible" description="Revisa el recurso y el paciente seleccionado." />;
  const scope = { rol: usuario.rol, ...(admin ? { id_paciente: params.pacienteId } : {}) };
  const base = admin ? `/pagos/pacientes/${params.pacienteId}` : '/pagos';
  const key = `${usuario.id_usuario}:${usuario.rol}:${params.pacienteId ?? 'propio'}:${params.id ?? 'lista'}:${epoch}`;
  return params.id ? <Detalle key={key} scope={scope} id={params.id} base={base} /> : <Listado key={key} scope={scope} base={base} />;
}
function Listado({ scope, base }) {
  const [tipo, setTipo] = useState('obligaciones'), [draft, setDraft] = useState({}), [filters, setFilters] = useState({ page: 1, per_page: 20 }), [error, setError] = useState('');
  const query = usePagoQuery(consultarPagos, { ...scope, ...filters, tipo });
  const field = name => ({ value: draft[name] ?? '', onChange: e => setDraft(v => ({ ...v, [name]: e.target.value })) });
  function submit(e) {
    e.preventDefault();
    if (['fecha_desde', 'fecha_hasta'].some(k => draft[k] && !esFechaCivil(draft[k])) || (draft.fecha_desde && draft.fecha_hasta && draft.fecha_desde > draft.fecha_hasta)) {
      setError('Revisa las fechas; la final no puede ser anterior a la inicial.'); return;
    }
    setError(''); setFilters({ ...draft, page: 1, per_page: Number(draft.per_page || 20) }); query.reload();
  }
  return <section className="workspace-page catalog-page payments-page">
    <PageHeading title={scope.id_paciente ? 'Pagos del paciente seleccionado' : 'Mis pagos'} description="Obligaciones e intentos de pruebas académicas. Sin dinero real. La situación financiera no bloquea tu atención." />
    {scope.id_paciente && <p>Ficha seleccionada: {scope.id_paciente}. Consulta exclusivamente de lectura. <Link to="/pagos">Cambiar paciente</Link></p>}
    <ActionGroup>{['obligaciones', 'historial'].map(t => <Button key={t} variant={tipo === t ? 'primary' : 'secondary'} aria-pressed={tipo === t}
      disabled={query.remaining > 0} onClick={() => { setTipo(t); setDraft({}); setFilters({ page: 1, per_page: 20 }); setError(''); }}>{t === 'obligaciones' ? 'Obligaciones' : 'Historial de intentos'}</Button>)}</ActionGroup>
    <p className="catalog-note">Las citas sin precio no son deudas de importe cero. Consulta {scope.id_paciente ? <Link to={`/recepcion/pacientes/${scope.id_paciente}/citas`}>el historial de citas</Link> : <Link to="/mis-citas">Mis citas</Link>} para distinguirlas de las citas antiguas no incorporadas.</p>
    <form className="catalog-filters" aria-label="Filtrar pagos" onSubmit={submit}>
      <FormField label="Desde" type="date" hint={tipo === 'obligaciones' ? 'Fecha de emisión de la obligación.' : 'Fecha de creación del intento.'} {...field('fecha_desde')} />
      <FormField label="Hasta" type="date" {...field('fecha_hasta')} />
      {tipo === 'obligaciones' ? [['exigible', 'Exigible'], ['liquidada', 'Liquidada'], ['vencida', 'Vencida regularizable'], ['operacion_en_verificacion', 'En verificación']].map(([name, label]) =>
        <SelectField key={name} label={label} {...field(name)}><option value="">Todas</option><option value="1">Sí</option><option value="0">No</option></SelectField>) : <>
        <SelectField label="Proveedor" {...field('proveedor')}><option value="">Todos</option>{Object.entries(PROVEEDORES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</SelectField>
        <SelectField label="Resultado del intento" {...field('resultado')}><option value="">Todos</option>{ESTADOS_INTENTO.map(v => <option key={v}>{v}</option>)}</SelectField>
      </>}
      <SelectField label="Resultados por página" value={draft.per_page ?? '20'} onChange={e => setDraft(v => ({ ...v, per_page: e.target.value }))}>
        {[10, 20, 50, 100].map(n => <option key={n}>{n}</option>)}</SelectField>
      <ActionGroup><Button type="submit" disabled={query.loading || query.remaining > 0}>Consultar pagos</Button><Button variant="quiet" disabled={query.remaining > 0} onClick={() => { setDraft({}); setFilters({ page: 1, per_page: 20 }); setError(''); query.reload(); }}>Restablecer filtros</Button></ActionGroup>
      {error && <p role="alert">{error}</p>}
    </form>
    <CatalogResults query={query} label="pagos" loadingLabel="Consultando pagos…" emptyTitle="No hay registros para estos filtros" emptyHint="Prueba otros filtros. Una lista vacía no acredita que una operación incierta haya fallado."
      onPage={page => setFilters(v => ({ ...v, page }))}>
      {tipo === 'historial' ? <HistorialPagos intentos={query.data?.data ?? []} detallePath={id => `${base}/obligaciones/${id}`} /> :
        <div className="catalog-table-wrap"><table className="catalog-table" role="table"><caption>Obligaciones por fecha de emisión descendente</caption><thead><tr>
          <th scope="col">Cita</th><th scope="col">Precio histórico</th><th scope="col">Situación financiera</th></tr></thead>
          <tbody>{query.data?.data.map(o => <tr key={o.id}><td><Link to={`${base}/obligaciones/${o.id}`}>{o.cita.codigo_cita}</Link><p>{formatearInstanteCita(o.cita.inicio_at)}</p><p>Estado de cita: {o.cita.estado}</p></td>
            <td><span className="catalog-cell-label">Precio histórico</span>Bs {decimalVisible(o.precio.monto_bob)}<p>USD {decimalVisible(o.precio.monto_usd)}</p></td>
            <td><span className="catalog-cell-label">Situación financiera</span>{situacionObligacion(o)}{o.operacion_en_verificacion && <p>Operación en verificación</p>}</td></tr>)}</tbody></table></div>}
    </CatalogResults>
  </section>;
}
function Detalle({ scope, id, base }) {
  const [pages, setPages] = useState({ intentos_page: 1, devoluciones_page: 1 });
  const [risk, setRisk] = useState(false);
  const query = usePagoQuery(consultarPagos, { ...scope, ...pages, tipo: 'detalle', id }), o = query.data;
  return <section className="workspace-page catalog-page payments-page">
    <Link className="back-link" to={base}>Volver a obligaciones e historial</Link>
    <PageHeading title="Detalle de obligación" description="Importes históricos y resultados registrados por el servidor. Pagar no confirma llegada ni atención." />
    {query.loading ? <QueryMessage loading title="Consultando obligación…" /> : query.failure ? <Alert><p>{query.failure.message}</p></Alert> : o && <>
      <section className="catalog-panel"><h2>Cita {o.cita.codigo_cita}</h2><p>Estado asistencial: {o.cita.estado}. Inicio: {formatearInstanteCita(o.cita.inicio_at)}.</p>
        <p>Situación financiera: <strong>{situacionObligacion(o)}</strong>.</p><PrecioPago precio={o.precio} />
        <p>Emitida: {formatearInstanteCita(o.emitida_at)}. Vencimiento: {formatearInstanteCita(o.vencimiento_at)}.</p>
        {o.vencida && <p>Puede regularizarse sin recargos ni bloqueo asistencial, si el servidor habilita el pago.</p>}
        {o.operacion_en_verificacion && <Alert variant="warning"><p>Hay una operación en verificación. No inicies otro pago para despejarla.</p></Alert>}
        {!o.exigible && <p>No permite nuevos pagos. El historial y las devoluciones se conservan aunque la cita esté cancelada.</p>}
        <p>Consulta realizada: {o.calculado_at ? formatearInstanteCita(o.calculado_at) : 'Instante no disponible'}.</p>
      </section>
      {scope.rol === ROLES.PACIENTE && o.intento_actual?.proveedor !== 'simulado' && <OperacionPago obligacion={o} onConsultar={query.reload} onRiskChange={setRisk} />}
      {scope.rol === ROLES.PACIENTE && o.simulacion?.habilitada && (o.simulacion.puede_iniciar || o.intento_actual?.proveedor === 'simulado') && <section className="catalog-panel" aria-label="Pago académico">
        <h2>Pago académico simulado</h2>
        <p>Registra el resultado en esta obligación, sin enviar dinero ni contactar con proveedores.</p>
        <Link className="button button--secondary" to={`/pagos/obligaciones/${o.id}/pagar`}>{o.intento_actual?.proveedor === 'simulado' ? 'Continuar pago académico' : 'Pagar'}</Link>
      </section>}
      <section><h2>Historial de intentos y cobros</h2>{o.intentos.data.length ? <HistorialPagos intentos={o.intentos.data} /> : <p>No hay intentos registrados en esta página.</p>}
        <Pagination meta={o.intentos.meta} label="intentos" onPage={intentos_page => { if (!risk) setPages(v => ({ ...v, intentos_page })); }} /></section>
      <section><h2>Devoluciones</h2>{o.devoluciones.data.length ? o.devoluciones.data.map(d => <DevolucionPago key={d.id} devolucion={d} />) : <p>No hay devoluciones registradas en esta página.</p>}
        <Pagination meta={o.devoluciones.meta} label="devoluciones" onPage={devoluciones_page => { if (!risk) setPages(v => ({ ...v, devoluciones_page })); }} />
        <p>Las incidencias y devoluciones pendientes o fallidas requieren revisión. Este portal no permite resolverlas ni marcar éxitos manuales.</p></section>
    </>}
    {query.remaining > 0 && <p role="status">Espera {query.remaining} segundos.</p>}
    <Button variant="secondary" disabled={risk || query.loading || query.remaining > 0} onClick={query.reload}>Actualizar detalle</Button>
  </section>;
}
