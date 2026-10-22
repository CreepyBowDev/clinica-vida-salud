import Alert from './Alert';
import Button from './Button';
import Pagination from './Pagination';
import QueryMessage from './QueryMessage';

export default function CatalogResults({ query, label, loadingLabel, emptyTitle, emptyHint, onPage, children }) {
  const { data, loading, failure, reload, remaining } = query;
  if (loading) return <QueryMessage loading title={loadingLabel} className="catalog-empty" />;
  if (failure) return <div className="catalog-failure"><Alert tabIndex="-1"><p>{failure.message}</p>
    {remaining > 0 && <p>Espera {remaining} segundos antes de volver a consultar.</p>}</Alert>
    <Button variant="secondary" disabled={remaining > 0} onClick={reload}>Volver a consultar</Button></div>;
  return <>
    {data.data.length ? children : <QueryMessage title={emptyTitle} description={emptyHint} className="catalog-empty" />}
    <Pagination meta={data.meta} label={label} onPage={onPage} />
  </>;
}
