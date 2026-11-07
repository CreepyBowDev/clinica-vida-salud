import { esIdentificador } from '../../usuarios/services/usuario.service';
import { ESTADOS_CITA, esFechaCivil } from '../../agenda/utils/agendaFormat';
import { esPeriodoReporte } from '../utils/periodoReporte';

function exigir(value) { if (!value) throw new Error('No se pudo verificar el reporte.'); }
function claves(value, keys) {
  exigir(value && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).sort().join('|') === [...keys].sort().join('|'));
}
function natural(value) { exigir(Number.isSafeInteger(value) && value >= 0); }
function iguales(a, b) { exigir(JSON.stringify(a) === JSON.stringify(b)); }
const dia = value => new Date(`${value}T00:00:00.000Z`);
const civil = value => value.toISOString().slice(0, 10);
const siguiente = value => civil(new Date(dia(value).getTime() + 86400000));
const medianoche = value => `${value}T04:00:00.000000Z`;

// Comprobar fronteras del contrato, nunca generar intervalos para presentar datos ausentes.
function validarPeriodo(meta) {
  const p = meta.periodo, f = meta.filtros;
  claves(p, ['desde', 'hasta', 'inicio_at', 'fin_exclusivo_at', 'referencia', 'criterio']);
  exigir(esPeriodoReporte({ desde: p.desde, hasta: p.hasta })
    && p.desde === (f.fecha ?? f.desde) && p.hasta === (f.fecha ?? f.hasta)
    && p.inicio_at === medianoche(p.desde) && p.fin_exclusivo_at === medianoche(siguiente(p.hasta))
    && p.referencia === (meta.reporte === 'pacientes/registrados' ? 'pacientes.creado_en' : 'citas.inicio_at')
    && p.criterio === (meta.reporte === 'pacientes/registrados' ? 'registro_en_periodo' : meta.reporte === 'agenda-diaria' ? 'interseccion_diaria' : 'inicio_en_periodo'));
}

function validarTemporal(serie, periodo) {
  const diaria = (dia(periodo.hasta) - dia(periodo.desde)) / 86400000 < 31;
  exigir(serie.agrupacion === (diaria ? 'diaria' : 'mensual'));
  let cursor = periodo.desde;
  for (const c of serie.categorias) {
    const i = c.intervalo;
    claves(i, ['desde', 'hasta', 'inicio_at', 'fin_exclusivo_at', 'parcial']);
    exigir(esFechaCivil(i.desde) && esFechaCivil(i.hasta) && i.desde === cursor && cursor <= periodo.hasta);
    const finMes = civil(new Date(Date.UTC(dia(cursor).getUTCFullYear(), dia(cursor).getUTCMonth() + 1, 0)));
    const fin = diaria ? cursor : (finMes < periodo.hasta ? finMes : periodo.hasta);
    const parcial = !diaria && (cursor.slice(8) !== '01' || fin !== finMes);
    const clave = diaria ? cursor : cursor.slice(0, 7);
    exigir(i.hasta === fin && i.parcial === parcial && i.inicio_at === medianoche(cursor)
      && i.fin_exclusivo_at === medianoche(siguiente(fin)) && c.id === `${diaria ? 'dia' : 'mes'}:${clave}`
      && c.etiqueta === clave + (parcial ? ' (parcial)' : ''));
    cursor = siguiente(fin);
  }
  exigir(cursor === siguiente(periodo.hasta));
}

export function validarEstadisticasReporte(meta, data) {
  validarPeriodo(meta);
  const e = meta.estadisticas, r = meta.reporte, f = meta.filtros;
  const pacientes = r === 'pacientes/registrados', cantidades = r === 'citas/cantidades';
  const minutos = ['citas/por-medico', 'agenda-diaria'].includes(r);
  const estados = pacientes ? [] : cantidades ? ESTADOS_CITA.slice(2) : r === 'citas/programadas' ? ['Programada'] : f.estado ? [f.estado] : ESTADOS_CITA;
  const dimensiones = pacientes ? ['actividad', 'periodo'] : r === 'citas/por-estado' ? ['periodo'] : minutos || cantidades ? ['estado'] : ['medico'];
  claves(e, ['version', 'unidad', 'estados_incluidos', 'resumen', 'series']);
  exigir(e.version === 1 && e.unidad === (pacientes ? 'fichas' : 'citas'));
  iguales(e.estados_incluidos, estados);
  claves(e.resumen, ['cantidad_total', 'duracion_total_minutos']);
  natural(e.resumen.cantidad_total);
  exigir(e.resumen.cantidad_total === (cantidades ? data.total : meta.total));
  if (minutos) natural(e.resumen.duracion_total_minutos); else exigir(e.resumen.duracion_total_minutos === null);
  exigir(Array.isArray(e.series) && e.series.length === dimensiones.length);
  e.series.forEach((s, index) => {
    claves(s, ['id', 'dimension', 'agrupacion', 'porcentajes', 'grafico', 'categorias']);
    const dimension = dimensiones[index];
    exigir(s.dimension === dimension && s.id === `por_${dimension}` && Array.isArray(s.categorias));
    if (dimension !== 'periodo') exigir(s.agrupacion === null);
    if (cantidades) {
      claves(s.porcentajes, ['denominador', 'decimales', 'redondeo']);
      exigir(s.porcentajes.denominador === e.resumen.cantidad_total && s.porcentajes.decimales === 1 && s.porcentajes.redondeo === 'HALF_UP');
    } else exigir(s.porcentajes === null);
    const ids = new Set();
    let suma = 0n, duracion = 0n, maximo = 0, positivas = 0;
    s.categorias.forEach((c, i) => {
      claves(c, ['id', 'etiqueta', 'cantidad', 'duracion_minutos', 'porcentaje', ({ medico: 'medico', estado: 'estado', actividad: 'activo', periodo: 'intervalo' })[dimension]]);
      exigir(typeof c.id === 'string' && typeof c.etiqueta === 'string' && !ids.has(c.id)); ids.add(c.id);
      natural(c.cantidad); suma += BigInt(c.cantidad); maximo = Math.max(maximo, c.cantidad); if (c.cantidad > 0) positivas++;
      if (minutos) { natural(c.duracion_minutos); duracion += BigInt(c.duracion_minutos); exigir(c.cantidad > 0 || c.duracion_minutos === 0); }
      else exigir(c.duracion_minutos === null);
      if (cantidades && e.resumen.cantidad_total > 0) {
        exigir(typeof c.porcentaje === 'string' && /^(?:(?:0|[1-9]\d?)\.\d|100\.0)$/.test(c.porcentaje)
          && (c.cantidad !== 0 || c.porcentaje === '0.0'));
      } else exigir(c.porcentaje === null);
      if (dimension === 'estado') {
        const estado = (cantidades ? ESTADOS_CITA.slice(2) : ESTADOS_CITA)[i];
        exigir(c.estado === estado && c.id === `estado:${estado}` && c.etiqueta === estado
          && (estados.includes(estado) || (c.cantidad === 0 && c.duracion_minutos === 0)));
        if (cantidades) exigir(c.cantidad === data[['atendidas', 'canceladas', 'inasistencias'][i]]);
      } else if (dimension === 'actividad') {
        exigir(c.activo === (i === 0) && c.id === `actividad:${i === 0 ? 'activo' : 'inactivo'}` && c.etiqueta === (i === 0 ? 'Activos' : 'Inactivos')
          && (f.activo === null || f.activo === c.activo || c.cantidad === 0));
      } else if (dimension === 'medico') {
        claves(c.medico, ['id_medico', 'codigo_medico', 'nombres', 'apellidos']);
        const m = c.medico;
        exigir(esIdentificador(m.id_medico) && ['codigo_medico', 'nombres', 'apellidos'].every(k => typeof m[k] === 'string')
          && c.id === `medico:${m.id_medico}` && c.etiqueta === `${m.apellidos}, ${m.nombres} — ${m.codigo_medico}`
          && c.cantidad > 0 && (!f.id_medico || m.id_medico === f.id_medico));
        if (i > 0) {
          const anterior = s.categorias[i - 1];
          exigir(anterior.cantidad > c.cantidad || (anterior.cantidad === c.cantidad && BigInt(anterior.medico.id_medico) < BigInt(m.id_medico)));
        }
      }
    });
    exigir(suma === BigInt(e.resumen.cantidad_total));
    if (minutos) exigir(duracion === BigInt(e.resumen.duracion_total_minutos));
    if (dimension === 'estado') exigir(s.categorias.length === (cantidades ? 3 : 5));
    if (dimension === 'actividad') exigir(s.categorias.length === 2);
    if (dimension === 'periodo') validarTemporal(s, meta.periodo);
    claves(s.grafico, ['tipo', 'visible', 'motivo_omision', 'maximo']);
    exigir(s.grafico.tipo === 'barras_horizontales' && s.grafico.maximo === maximo && s.grafico.visible === (positivas >= 2)
      && s.grafico.motivo_omision === (positivas >= 2 ? null : positivas === 0 ? 'sin_datos' : 'unica_categoria_con_datos'));
  });
  // Objeto cerrado ya validado: preservar strings/ceros/nulls, sin recalcular indicadores.
  return e;
}
