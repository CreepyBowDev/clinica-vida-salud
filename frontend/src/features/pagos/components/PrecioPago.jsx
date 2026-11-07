import { decimalVisible } from '../utils/pagoFormat';
import '../styles/pagos.css';

export default function PrecioPago({ precio }) {
  if (!precio) return null;
  return <dl className="payment-price" aria-label="Importes históricos">
    <div className="payment-price__original"><dt>Monto original</dt><dd><strong>Bs {decimalVisible(precio.monto_bob)}</strong></dd></div>
    <div className="payment-price__converted"><dt>Importe al proveedor</dt><dd><strong>USD {decimalVisible(precio.monto_usd)}</strong></dd></div>
    <div className="payment-price__rate"><dt>Tasa académica histórica</dt><dd>{decimalVisible(precio.tasa_bob_por_usd)} BOB por USD</dd></div>
  </dl>;
}
