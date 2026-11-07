export default function ProgresoPago({ actual }) {
  return <ol className="payment-progress" aria-label="Progreso del recorrido académico">
    {['Preparar', 'Revisar importe', 'Continuar operación', 'Resultado consultado'].map((label, index) =>
      <li key={label} aria-current={index === actual ? 'step' : undefined}>
        <span aria-hidden="true">{index + 1}</span>{label}
      </li>)}
  </ol>;
}
