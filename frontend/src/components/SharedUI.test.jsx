import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Button from './Button';
import FormField from './FormField';
import SelectField from './SelectField';
import Alert from './Alert';
import PageHeading from './PageHeading';
import ActionGroup from './ActionGroup';
import Pagination from './Pagination';
import CatalogResults from './CatalogResults';

describe('controles compartidos: contratos y accesibilidad', () => {
  it.each(['primary', 'secondary', 'quiet', 'danger'])('botón %s conserva nombre, tipo y callback', async variant => {
    const click = vi.fn(); render(<Button variant={variant} onClick={click}>Acción descriptiva larga</Button>);
    const button = screen.getByRole('button', { name: 'Acción descriptiva larga' });
    expect(button).toHaveAttribute('type', 'button'); expect(button).toHaveClass(`button--${variant}`);
    await userEvent.click(button); expect(click).toHaveBeenCalledTimes(1);
  });
  it.each([{ loading: true }, { disabled: true }])('no envía al estar ocupado o deshabilitado: %o', async props => {
    const click = vi.fn(); render(<Button {...props} onClick={click}>Guardar cambios</Button>);
    await userEvent.click(screen.getByRole('button')); expect(click).not.toHaveBeenCalled(); expect(screen.getByRole('button')).toBeDisabled();
    if (props.loading) expect(screen.getByRole('button')).toHaveAttribute('aria-busy', 'true');
  });
  it('mantiene submit explícito y no pierde clases del consumidor', () => {
    render(<Button type="submit" className="consumer">Guardar</Button>);
    expect(screen.getByRole('button')).toHaveAttribute('type', 'submit'); expect(screen.getByRole('button')).toHaveClass('consumer', 'button');
  });
  it.each([['input', FormField], ['select', SelectField]])('%s enlaza etiqueta, ayuda, error y descripción externa', (_, Component) => {
    render(<><p id="external">Contexto adicional</p><Component id="control" label="Campo" hint="Ayuda visible" error="Revisa este campo" aria-describedby="external" {...(Component === SelectField ? { children: <option>Valor</option> } : {})} /></>);
    const field = screen.getByLabelText('Campo');
    expect(field).toHaveAttribute('id', 'control'); expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(field).toHaveAccessibleDescription('Contexto adicional Ayuda visible Revisa este campo');
    expect(field).not.toHaveAttribute('hint');
  });
  it.each([['input', FormField], ['select', SelectField]])('%s conserva valor, eventos, required y disabled', async (_, Component) => {
    const options = Component === SelectField ? { children: <><option value="a">A</option><option value="b">B</option></> } : {};
    const change = vi.fn(); const { rerender } = render(<Component label="Campo" value="a" required onChange={change} {...options} />);
    expect(screen.getByLabelText('Campo')).toBeRequired();
    fireEvent.change(screen.getByLabelText('Campo'), { target: { value: 'b' } }); expect(change).toHaveBeenCalledTimes(1);
    rerender(<Component label="Campo" value="a" onChange={change} disabled {...options} />); expect(screen.getByLabelText('Campo')).toBeDisabled();
    expect(screen.getByLabelText('Campo')).toHaveAttribute('aria-invalid', 'false');
  });
  it('genera IDs únicos y conserva control trailing y readonly', () => {
    render(<><FormField label="Primero" readOnly defaultValue="Dato" trailing={<button>Ver dato</button>} /><FormField label="Segundo" /></>);
    expect(screen.getByLabelText('Primero').id).not.toBe(screen.getByLabelText('Segundo').id);
    expect(screen.getByLabelText('Primero')).toHaveAttribute('readonly'); expect(screen.getByRole('button', { name: 'Ver dato' })).toBeInTheDocument();
  });
  it.each(['error', 'warning', 'success', 'info'])('aviso %s conserva semántica, mensaje y clases base', variant => {
    render(<Alert variant={variant} className="consumer">Mensaje comprensible</Alert>);
    expect(screen.getByRole(variant === 'error' ? 'alert' : 'status')).toHaveClass('alert', `alert--${variant}`, 'consumer');
    expect(screen.getByText('Mensaje comprensible')).toBeInTheDocument();
  });
});

describe('patrones de composición y consulta', () => {
  it('encabezado mantiene un h1, descripción y enlace real sin imponer navegación', () => {
    render(<PageHeading title="Título extenso" description="Contexto de la tarea" actions={<a href="/destino">Crear registro</a>} />);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Título extenso');
    expect(screen.getByText('Contexto de la tarea')).toBeInTheDocument(); expect(screen.getByRole('link')).toHaveAttribute('href', '/destino');
  });
  it('encabezado admite ausencia de acciones y grupo no altera orden o tipos', () => {
    const { container } = render(<><PageHeading title="Consulta" /><ActionGroup className="consumer" aria-label="Acciones"><Button>Guardar</Button><a href="/volver">Volver</a></ActionGroup></>);
    expect(container.querySelector('.page-heading .action-group')).toBeNull();
    expect(container.querySelector('.consumer').firstChild).toHaveTextContent('Guardar'); expect(screen.getByRole('link')).toHaveTextContent('Volver');
  });
  it.each([[1, 3, true, false], [2, 3, false, false], [3, 3, false, true], [1, 1, true, true]])('paginación %s/%s usa límites del servidor', async (page, last, previousDisabled, nextDisabled) => {
    const onPage = vi.fn(); render(<Pagination meta={{ total: 42, current_page: page, last_page: last }} label="elementos" onPage={onPage} />);
    const previous = screen.getByRole('button', { name: 'Anterior' }), next = screen.getByRole('button', { name: 'Siguiente' });
    expect(previous.disabled).toBe(previousDisabled); expect(next.disabled).toBe(nextDisabled);
    expect(screen.getByRole('navigation', { name: 'Paginación de elementos' })).toHaveTextContent(`42 elementos · página ${page} de ${last}`);
    if (!nextDisabled) { await userEvent.click(next); expect(onPage).toHaveBeenLastCalledWith(page + 1); }
    if (!previousDisabled) { await userEvent.click(previous); expect(onPage).toHaveBeenLastCalledWith(page - 1); }
  });
  const props = { label: 'elementos', loadingLabel: 'Consultando elementos…', emptyTitle: 'Sin resultados', emptyHint: 'Prueba otro filtro.', onPage: vi.fn() };
  const ready = { data: { data: [], meta: { total: 0, current_page: 1, last_page: 1 } }, reload: vi.fn(), remaining: 0 };
  it('carga retira datos previos y conserva anuncio textual', () => {
    render(<CatalogResults {...props} query={{ ...ready, loading: true }}><p>Datos antiguos</p></CatalogResults>);
    expect(screen.getByRole('status')).toHaveTextContent('Consultando elementos…'); expect(screen.queryByText('Datos antiguos')).not.toBeInTheDocument();
  });
  it.each([0, 30])('fallo respeta espera %s sin reintento automático', async remaining => {
    const reload = vi.fn(); render(<CatalogResults {...props} query={{ ...ready, reload, remaining, failure: { message: 'No se pudo consultar.' } }} />);
    expect(screen.getByRole('alert')).toHaveTextContent('No se pudo consultar.'); expect(reload).not.toHaveBeenCalled();
    const button = screen.getByRole('button', { name: 'Volver a consultar' }); expect(button.disabled).toBe(remaining > 0);
    await userEvent.click(button); expect(reload).toHaveBeenCalledTimes(remaining ? 0 : 1);
  });
  it('vacío presenta orientación y conserva paginación del servidor', () => {
    render(<CatalogResults {...props} query={ready} />);
    expect(screen.getByRole('heading', { name: 'Sin resultados' })).toBeInTheDocument(); expect(screen.getByText('Prueba otro filtro.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
  });
  it('datos recibidos renderizan contenido sin estado vacío', () => {
    render(<CatalogResults {...props} query={{ ...ready, data: { ...ready.data, data: [{ id: '1' }] } }}><p>Contenido autorizado</p></CatalogResults>);
    expect(screen.getByText('Contenido autorizado')).toBeInTheDocument(); expect(screen.queryByRole('heading', { name: 'Sin resultados' })).not.toBeInTheDocument();
  });
});
