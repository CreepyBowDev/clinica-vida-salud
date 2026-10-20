import { useId } from 'react';

export default function SelectField({ label, error, children, ...props }) {
  const id = useId();
  return <div className="field"><label htmlFor={id}>{label}</label>
    <select {...props} id={id} aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : undefined}>{children}</select>
    {error && <p className="field-error" id={`${id}-error`}>{error}</p>}
  </div>;
}
