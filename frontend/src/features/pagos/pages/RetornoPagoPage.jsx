import { Link, useParams } from 'react-router-dom';
import PageHeading from '../../../components/PageHeading';
import OperacionPago from '../components/OperacionPago';
import { esUuid, PROVEEDORES } from '../utils/pagoFormat';
import '../../../styles/catalogs.css';
import '../styles/pagos.css';

export default function RetornoPagoPage() {
  const { proveedor, intento } = useParams();
  return <section className="workspace-page catalog-page payments-page"><PageHeading title="Retorno al portal" description="Volver del proveedor no confirma el pago. Solo el servidor puede verificar su resultado." />
    <div className="payment-scope"><div><strong>Regreso de un proveedor externo de prueba</strong><p>Consulta el resultado antes de interpretar el retorno como pago. No se captura ningún importe al abrir esta pantalla.</p></div></div>
    {esUuid(intento) && PROVEEDORES[proveedor] ? <OperacionPago key={intento} intento={{ id: intento, proveedor }} retorno /> : <p>No se recibió un localizador local válido. Consulta tus obligaciones; no uses parámetros del proveedor como prueba de pago.</p>}
    <Link className="button button--secondary" to="/pagos">Consultar mis obligaciones</Link>
  </section>;
}
