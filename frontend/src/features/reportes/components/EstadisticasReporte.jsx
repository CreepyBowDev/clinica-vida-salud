import { useEffect, useId, useRef, useState } from 'react';
import { formatearFechaCivil } from '../../agenda/utils/agendaFormat';

const numero = value => value.toLocaleString('es-ES');
const titulos = { medico: 'Médico', estado: 'Estado', actividad: 'Actividad actual', periodo: 'Período' };

function SerieEstadistica({ serie, unidad, minutos }) {
  const id = useId(), wrap = useRef(null);
  const [desborda, setDesborda] = useState(false);
  useEffect(() => {
    const medir = () => setDesborda(wrap.current.scrollWidth > wrap.current.clientWidth);
    medir();
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(medir);
    observer?.observe(wrap.current); window.addEventListener('resize', medir);
    return () => { observer?.disconnect(); window.removeEventListener('resize', medir); };
  }, [serie]);
  const titulo = `Cantidades por ${titulos[serie.dimension].toLowerCase()}`;
  return <section className="report-stat-series" aria-labelledby={id}>
    <h3 id={id}>{titulo}</h3>
    {serie.dimension === 'periodo' && <p>Agrupación {serie.agrupacion}. Intervalos de la sede, America/La_Paz; incluye períodos sin datos.</p>}
    {serie.grafico.visible ? <>
      <p>{titulo}; datos completos en la tabla siguiente.</p>
      <div className="report-bars" aria-hidden="true">
        <p>Escala común desde 0 hasta {numero(serie.grafico.maximo)} {unidad}</p>
        {serie.categorias.map(c => <div className="report-bar-row" key={c.id}>
          <span>{c.etiqueta}</span><strong>{numero(c.cantidad)} {unidad}</strong>
          <div className="report-bar-track"><span className="report-bar-fill" style={{ width: `${c.cantidad / serie.grafico.maximo * 100}%` }} /></div>
        </div>)}
      </div>
    </> : <p>Gráfico omitido: {serie.grafico.motivo_omision === 'sin_datos' ? 'sin datos.' : 'una única categoría con datos.'} Se conserva la tabla completa.</p>}
    <div ref={wrap} className="report-stat-scroll" role={desborda ? 'region' : undefined} aria-label={desborda ? `Tabla: ${titulo}` : undefined} tabIndex={desborda ? 0 : undefined}>
      <table className="report-stat-table">
        <caption>{titulo} — conjunto completo filtrado ({unidad})</caption>
        <thead><tr><th scope="col">{titulos[serie.dimension]}</th>
          {serie.dimension === 'periodo' && <th scope="col">Intervalo incluido (fechas de la sede)</th>}
          <th scope="col">Cantidad ({unidad})</th>{minutos && <th scope="col">Duración histórica completa (minutos)</th>}
          {serie.porcentajes && <th scope="col">Porcentaje (%)</th>}</tr></thead>
        <tbody>{serie.categorias.map(c => <tr key={c.id}><th scope="row">{c.etiqueta}</th>
          {c.intervalo && <td>{formatearFechaCivil(c.intervalo.desde)} al {formatearFechaCivil(c.intervalo.hasta)}{c.intervalo.parcial && ' (mes parcial)'}</td>}
          <td>{numero(c.cantidad)}</td>{minutos && <td>{numero(c.duracion_minutos)}</td>}
          {serie.porcentajes && <td>{c.porcentaje === null ? 'No aplica' : `${c.porcentaje.replace('.', ',')} %`}</td>}</tr>)}</tbody>
      </table>
    </div>
    {serie.porcentajes && <p>Denominador: {numero(serie.porcentajes.denominador)} citas en Atendida, Cancelada e Inasistencia.</p>}
    {serie.porcentajes?.denominador > 0 && <p>Los porcentajes pueden sumar 99,9 % o 100,1 % por redondeo.</p>}
  </section>;
}

export default function EstadisticasReporte({ meta }) {
  const id = useId(), e = meta.estadisticas;
  const minutos = e.resumen.duracion_total_minutos !== null;
  return <section className="report-statistics" aria-labelledby={id}>
    <h2 id={id}>Resumen estadístico</h2>
    <p>Conjunto completo con los filtros aplicados; independiente de la página visible.</p>
    <dl className="report-stat-summary"><div><dt>Cantidad total ({e.unidad})</dt><dd>{numero(e.resumen.cantidad_total)}</dd></div>
      {minutos && <div><dt>Duración histórica completa (minutos)</dt><dd>{numero(e.resumen.duracion_total_minutos)}</dd></div>}</dl>
    {e.resumen.cantidad_total === 0 && <p>Sin resultados para los filtros aplicados.</p>}
    {minutos && <p>Duración histórica completa de las citas incluidas; no representa tiempo trabajado, ocupación ni tiempo atendido.</p>}
    {meta.reporte === 'agenda-diaria' && <p>Las citas que cruzan medianoche pueden aparecer en dos días con su duración completa, sin recorte. No sumar agendas como cantidades o minutos únicos.</p>}
    {e.unidad === 'fichas' && <p>Actividad actual de las fichas; períodos por creación de ficha, no alta de cuenta ni incorporación de CI. Cada serie representa el mismo conjunto: no sumar ambas series.</p>}
    {e.series.map(s => <SerieEstadistica key={s.id} serie={s} unidad={e.unidad} minutos={minutos} />)}
  </section>;
}
