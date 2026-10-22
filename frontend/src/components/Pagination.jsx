import Button from './Button';
import ActionGroup from './ActionGroup';

export default function Pagination({ meta, label, onPage }) {
  return <nav className="pagination" aria-label={`Paginación de ${label}`}>
    <p>{meta.total} {label} · página {meta.current_page} de {meta.last_page}</p>
    <ActionGroup><Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Anterior</Button>
      <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Siguiente</Button></ActionGroup>
  </nav>;
}
