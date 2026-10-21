import Alert from './Alert';
import Button from './Button';

export default function CatalogResults({ query, label, loadingLabel, emptyTitle, emptyHint, onPage, children }) {
  const { data, loading, failure, reload, remaining } = query;
  if (loading) return <p role="status" className="catalog-empty">{loadingLabel}</p>;
  if (failure) return <div className="catalog-failure"><Alert tabIndex="-1"><p>{failure.message}</p>
    {remaining > 0 && <p>Espera {remaining} segundos antes de volver a consultar.</p>}</Alert>
    <Button variant="secondary" disabled={remaining > 0} onClick={reload}>Volver a consultar</Button></div>;
  return <>
    {data.data.length ? children : <div className="catalog-empty"><h2>{emptyTitle}</h2><p>{emptyHint}</p></div>}
    <nav className="catalog-pagination" aria-label={`Paginación de ${label}`}><p>{data.meta.total} {label} · página {data.meta.current_page} de {data.meta.last_page}</p><div>
      <Button variant="secondary" disabled={data.meta.current_page <= 1} onClick={() => onPage(data.meta.current_page - 1)}>Anterior</Button>
      <Button variant="secondary" disabled={data.meta.current_page >= data.meta.last_page} onClick={() => onPage(data.meta.current_page + 1)}>Siguiente</Button>
    </div></nav>
  </>;
}
