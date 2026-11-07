import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it } from 'vitest';
import EstadisticasReporte from '../../components/EstadisticasReporte';
import { variantes } from '../utils/variantesEstadisticas';
import { cantidadesReporte, agendaReporte } from '../utils/cantidadesAgendaFixtures';
import { pacientesReporte } from '../utils/pacientesReporteFixtures';
import { reporte } from '../utils/reporteFixtures';
import { categoria, serie } from '../utils/estadisticasFixtures';

it.each(variantes)('%s: resumen antes de tablas completas accesibles, incluso vacío', (tipo, load, seleccion, fixture) => {
  const value = fixture({ fecha: '2026-10-31', ...seleccion }, 0);
  render(<EstadisticasReporte meta={value.meta} />);
  expect(screen.getByRole('heading', { name: 'Resumen estadístico' })).toBeInTheDocument();
  expect(screen.getByText('Sin resultados para los filtros aplicados.')).toBeInTheDocument();
  const tables = screen.getAllByRole('table');
  expect(tables).toHaveLength(value.meta.estadisticas.series.length);
  tables.forEach((table, i) => {
    expect(table.querySelector('caption')).toHaveTextContent('conjunto completo filtrado');
    expect(within(table).getAllByRole('row')).toHaveLength(value.meta.estadisticas.series[i].categorias.length + 1);
    expect(table.querySelectorAll('thead th[scope="col"]').length).toBeGreaterThan(1);
  });
  expect(document.querySelector('.report-bars')).toBeNull();
});
it('barras desde cero proporcionales 1:4 con ceros sin mínimo; tabla no depende de color', () => {
  const value = cantidadesReporte({ fecha: '2026-10-31' }, { atendidas: 1, canceladas: 4, inasistencias: 0, total: 5 });
  render(<EstadisticasReporte meta={value.meta} />);
  const bars = [...document.querySelectorAll('.report-bar-fill')];
  expect(bars.map(b => b.style.width)).toEqual(['25%', '100%', '0%']);
  expect(document.querySelector('.report-bars')).toHaveAttribute('aria-hidden', 'true');
  expect(screen.getByText('Escala común desde 0 hasta 4 citas')).toBeInTheDocument();
  const table = screen.getByRole('table');
  expect(table).toHaveTextContent('20,0 %'); expect(table).toHaveTextContent('80,0 %'); expect(table).toHaveTextContent('0,0 %');
  expect(within(table).getAllByRole('rowheader').map(n => n.textContent)).toEqual(['Atendida', 'Cancelada', 'Inasistencia']);
});
it.each([
  [1, 1, 1, '33,3 %'], [1, 1, 14, '6,3 %'], [1, 1, 4, '16,7 %'], [0, 0, 0, 'No aplica'],
])('porcentajes strings del servidor, denominador y advertencia %s/%s/%s', (a, c, i, texto) => {
  const value = cantidadesReporte({ fecha: '2026-10-31' }, { atendidas: a, canceladas: c, inasistencias: i, total: a + c + i });
  render(<EstadisticasReporte meta={value.meta} />);
  expect(screen.getByRole('table')).toHaveTextContent(texto);
  expect(Boolean(screen.queryByText('Los porcentajes pueden sumar 99,9 % o 100,1 % por redondeo.'))).toBe(a + c + i > 0);
  expect(document.body).not.toHaveTextContent(/tasas|NaN|Infinity/);
});
it('no recalcula ni ajusta strings recibidos al presentar porcentajes', () => {
  const value = cantidadesReporte({ fecha: '2026-10-31' });
  value.meta.estadisticas.series[0].categorias[0].porcentaje = '27.1';
  render(<EstadisticasReporte meta={value.meta} />);
  expect(screen.getByRole('table')).toHaveTextContent('27,1 %');
});
it('minutos históricos completos y aviso de agenda no sumable', () => {
  const value = agendaReporte({ fecha: '2026-10-31', id_medico: '9007199254740993' });
  value.meta.estadisticas.resumen.duracion_total_minutos = 40;
  value.meta.estadisticas.series[0].categorias[0].duracion_minutos = 40;
  render(<EstadisticasReporte meta={value.meta} />);
  expect(screen.getByRole('table')).toHaveTextContent('Duración histórica completa (minutos)');
  expect(screen.getByRole('table')).toHaveTextContent('40');
  expect(screen.getByText(/No sumar agendas como cantidades o minutos únicos/)).toBeInTheDocument();
});
it('meses parciales e intervalos vacíos conservan fechas y ceros', () => {
  const value = pacientesReporte({ desde: '2026-01-20', hasta: '2026-02-20' }, 2);
  render(<EstadisticasReporte meta={value.meta} />);
  const table = screen.getByRole('table', { name: /Cantidades por período/ });
  expect(table).toHaveTextContent('2026-01 (parcial)20/01/2026 al 31/01/2026 (mes parcial)2');
  expect(table).toHaveTextContent('2026-02 (parcial)01/02/2026 al 20/02/2026 (mes parcial)0');
  expect(screen.getByText(/Cada serie representa el mismo conjunto: no sumar ambas series/)).toBeInTheDocument();
});
it('nombres largos escapados, homónimos/códigos y valores grandes sin truncar ni topN', () => {
  const value = reporte({}, 12), apellido = '<img src=x onerror=alert(1)> Nombre largo '.repeat(12);
  value.meta.estadisticas.series = [serie('medico', Array.from({ length: 12 }, (_, i) => {
    const medico = { id_medico: String(i + 1), codigo_medico: `MED-${i + 1}`, nombres: 'Ana', apellidos: apellido };
    return categoria(`medico:${i + 1}`, `${apellido}, Ana — MED-${i + 1}`, i === 0 ? 9007199254740980 : 1, { medico });
  }))];
  value.meta.estadisticas.resumen.cantidad_total = 9007199254740991;
  render(<EstadisticasReporte meta={value.meta} />);
  expect(screen.getByRole('table').querySelectorAll('tbody tr')).toHaveLength(12);
  expect(screen.getByRole('table')).toHaveTextContent('MED-12'); expect(screen.getByRole('table')).toHaveTextContent(apellido.trim());
  expect(document.querySelector('img')).toBeNull(); expect(document.body).toHaveTextContent('9.007.199.254.740.991');
});
it('contenedor desplazable solo enfocable al desbordar; foco conservado', () => {
  render(<EstadisticasReporte meta={cantidadesReporte({ fecha: '2026-10-31' }).meta} />);
  const wrap = document.querySelector('.report-stat-scroll'); expect(wrap).not.toHaveAttribute('tabindex');
  Object.defineProperties(wrap, { scrollWidth: { value: 800 }, clientWidth: { value: 300 } });
  fireEvent(window, new Event('resize'));
  expect(wrap).toHaveAttribute('tabindex', '0'); expect(wrap).toHaveAttribute('role', 'region');
  wrap.focus(); expect(wrap).toHaveFocus();
});
