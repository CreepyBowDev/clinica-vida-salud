import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import QueryMessage from '../../../components/QueryMessage';

export default function CantidadesReporte({ query }) {
  if (query.loading) return <QueryMessage loading title="Consultando cantidades…" className="catalog-empty" />;
  if (query.failure) return <div className="catalog-failure"><Alert><p>{query.failure.message}</p>
    {query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
    <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button></div>;
  const counts = query.data.data;
  return <section aria-label="Cantidades del período" aria-live="polite">
    <h2>Cantidades según el estado actual</h2>
    <dl className="catalog-data record-data">
      <div><dt>Atendidas</dt><dd>{counts.atendidas}</dd></div>
      <div><dt>Canceladas</dt><dd>{counts.canceladas}</dd></div>
      <div><dt>Inasistencias</dt><dd>{counts.inasistencias}</dd></div>
      <div><dt>Total del servidor</dt><dd>{counts.total}</dd></div>
    </dl>
  </section>;
}
