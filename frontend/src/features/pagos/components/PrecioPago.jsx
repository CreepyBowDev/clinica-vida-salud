import { decimalVisible } from '../utils/pagoFormat';

export default function PrecioPago({ precio }) {
  if (!precio) return null;
  return <dl className="catalog-data payment-price" aria-label="Importes históricos">
    <div><dt>Monto original</dt><dd>Bs {decimalVisible(precio.monto_bob)}</dd></div>
    <div><dt>Tasa académica histórica</dt><dd>{decimalVisible(precio.tasa_bob_por_usd)} BOB por USD</dd></div>
    <div><dt>Importe al proveedor</dt><dd><strong>USD {decimalVisible(precio.monto_usd)}</strong></dd></div>
  </dl>;
}
