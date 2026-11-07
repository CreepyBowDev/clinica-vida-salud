// Contratos sintéticos para transporte simulado; no son capturas Laravel.
const estados = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
const civil = d => d.toISOString().slice(0, 10);
const next = d => civil(new Date(Date.parse(`${d}T00:00:00Z`) + 86400000));
const utc = d => `${d}T04:00:00.000000Z`;
export function serie(dimension, categorias, agrupacion = null, denominador = null) {
  const positivas = categorias.filter(c => c.cantidad > 0).length;
  return { id: `por_${dimension}`, dimension, agrupacion,
    porcentajes: denominador === null ? null : { denominador, decimales: 1, redondeo: 'HALF_UP' },
    grafico: { tipo: 'barras_horizontales', visible: positivas >= 2, motivo_omision: positivas >= 2 ? null : positivas === 0 ? 'sin_datos' : 'unica_categoria_con_datos',
      maximo: Math.max(0, ...categorias.map(c => c.cantidad)) }, categorias };
}
export function categoria(id, etiqueta, cantidad, extra, duracion_minutos = null, porcentaje = null) {
  return { id, etiqueta, cantidad, duracion_minutos, porcentaje, ...extra };
}
export function conEstadisticas(value) {
  const m = value.meta, f = m.filtros, r = m.reporte;
  const pacientes = r === 'pacientes/registrados', cantidades = r === 'citas/cantidades';
  const minutos = ['citas/por-medico', 'agenda-diaria'].includes(r);
  const total = cantidades ? value.data.total : m.total;
  const desde = f.fecha ?? f.desde, hasta = f.fecha ?? f.hasta;
  m.periodo = { desde, hasta, inicio_at: utc(desde), fin_exclusivo_at: utc(next(hasta)),
    referencia: pacientes ? 'pacientes.creado_en' : 'citas.inicio_at', criterio: pacientes ? 'registro_en_periodo' : r === 'agenda-diaria' ? 'interseccion_diaria' : 'inicio_en_periodo' };
  const incluidos = pacientes ? [] : cantidades ? estados.slice(2) : r === 'citas/programadas' ? ['Programada'] : f.estado ? [f.estado] : estados;
  let series;
  if (minutos || cantidades) {
    series = [serie('estado', (cantidades ? estados.slice(2) : estados).map((estado, i) => {
      const n = cantidades ? value.data[['atendidas', 'canceladas', 'inasistencias'][i]] : estado === incluidos[0] ? total : 0;
      // Solo el doble genera porcentajes; nunca se importa desde producto.
      const decimas = cantidades && total > 0 ? (1000n * BigInt(n) * 2n + BigInt(total)) / (2n * BigInt(total)) : null;
      return categoria(`estado:${estado}`, estado, n, { estado }, minutos ? n * 15 : null,
        decimas === null ? null : `${decimas / 10n}.${decimas % 10n}`);
    }), null, cantidades ? total : null)];
  } else if (pacientes || r === 'citas/por-estado') {
    const diaria = (Date.parse(hasta) - Date.parse(desde)) / 86400000 < 31, cats = [];
    for (let cursor = desde; cursor <= hasta;) {
      const date = new Date(`${cursor}T00:00:00Z`);
      const finMes = civil(new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)));
      const fin = diaria ? cursor : finMes < hasta ? finMes : hasta;
      const parcial = !diaria && (cursor.slice(8) !== '01' || fin !== finMes), clave = diaria ? cursor : cursor.slice(0, 7);
      cats.push(categoria(`${diaria ? 'dia' : 'mes'}:${clave}`, clave + (parcial ? ' (parcial)' : ''), cats.length ? 0 : total,
        { intervalo: { desde: cursor, hasta: fin, inicio_at: utc(cursor), fin_exclusivo_at: utc(next(fin)), parcial } }));
      cursor = next(fin);
    }
    series = [serie('periodo', cats, diaria ? 'diaria' : 'mensual')];
    if (pacientes) series.unshift(serie('actividad', [true, false].map(activo => categoria(`actividad:${activo ? 'activo' : 'inactivo'}`,
      activo ? 'Activos' : 'Inactivos', activo === (f.activo ?? false) ? total : 0, { activo }))));
  } else {
    const medico = { id_medico: f.id_medico || '9007199254740993', codigo_medico: 'MED-FICTICIO', nombres: 'Médica', apellidos: 'De prueba' };
    series = [serie('medico', total ? [categoria(`medico:${medico.id_medico}`, `${medico.apellidos}, ${medico.nombres} — ${medico.codigo_medico}`, total, { medico })] : [])];
  }
  m.estadisticas = { version: 1, unidad: pacientes ? 'fichas' : 'citas', estados_incluidos: incluidos,
    resumen: { cantidad_total: total, duracion_total_minutos: minutos ? total * 15 : null }, series };
  return value;
}
