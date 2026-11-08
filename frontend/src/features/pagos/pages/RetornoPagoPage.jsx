import { Link, useLocation, useParams } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { paymentPresentation } from '../../../constants/paymentPresentation';
import { ROLES } from '../../../constants/roles';
import PageHeading from '../../../components/PageHeading';
import OperacionPago from '../components/OperacionPago';
import { esUuid, PROVEEDORES } from '../utils/pagoFormat';
import '../../../styles/catalogs.css';
import '../styles/pagos.css';

export default function RetornoPagoPage() {
  const { proveedor, intento } = useParams();
  const location = useLocation(), entry = paymentPresentation(location.pathname, location.search, ROLES.PACIENTE);
  const [risk, setRisk] = useState(false), [mounted, setMounted] = useState(entry.valid);
  useEffect(() => { if (!risk) setMounted(entry.valid); }, [risk, entry.valid]);
  return <section className="workspace-page catalog-page payments-page"><PageHeading title="Retorno al portal" description="Volver del proveedor no confirma el pago. Solo el servidor puede verificar su resultado." />
    <div className="payment-scope"><div><strong>Regreso de un proveedor externo de prueba</strong><p>Consulta el resultado antes de interpretar el retorno como pago. No se captura ningún importe al abrir esta pantalla.</p></div></div>
    {!entry.valid && <p role="alert">Vista de pagos no disponible. Vuelve a tus obligaciones.</p>}
    {mounted && esUuid(intento) && PROVEEDORES[proveedor] ? <OperacionPago key={intento} onRiskChange={setRisk} suspended={!entry.valid} intento={{ id: intento, proveedor }} retorno /> : entry.valid && <p>No se recibió un localizador local válido. Consulta tus obligaciones; no uses parámetros del proveedor como prueba de pago.</p>}
    <Link className="button button--secondary" to="/pagos?vista=obligaciones">Consultar mis obligaciones</Link>
  </section>;
}
