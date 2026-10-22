export default function WorkflowProgress({ label, steps, current }) {
  return <ol className="workflow-progress" aria-label={label}>{steps.map((step, index) => <li key={step.id} aria-current={current === step.id ? 'step' : undefined}>
    <span className="workflow-number" aria-hidden="true">{index + 1}</span><span>{step.label}{current === step.id && <small>Etapa actual</small>}</span>
  </li>)}</ol>;
}
