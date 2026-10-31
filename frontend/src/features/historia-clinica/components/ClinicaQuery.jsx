import Alert from '../../../components/Alert';
import Button from '../../../components/Button';
import QueryMessage from '../../../components/QueryMessage';

export default function ClinicaQuery({ query, children }) {
  return <div aria-busy={query.loading}>{query.loading ? <QueryMessage loading title="Consultando información clínica…" /> : query.failure ? <div className="query-failure">
    <Alert><p>{query.failure.message}</p>{query.remaining > 0 && <p>Espera {query.remaining} segundos antes de volver a consultar.</p>}</Alert>
    {![401, 403, 404, 422].includes(query.failure.status) && <Button variant="secondary" disabled={query.remaining > 0} onClick={query.reload}>Volver a consultar</Button>}
  </div> : children(query.data)}</div>;
}
