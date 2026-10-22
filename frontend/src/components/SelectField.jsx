import { useId } from 'react';

export default function SelectField({ label, error, hint, children, id: providedId, 'aria-describedby': externalDescription, ...props }) {
  const generatedId = useId();
  const id = providedId ?? generatedId;
  const describedBy = [externalDescription, hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ');
  return <div className="field"><label htmlFor={id}>{label}</label>
    <select {...props} id={id} aria-invalid={Boolean(error)} aria-describedby={describedBy || undefined}>{children}</select>
    {hint && <p className="field-hint" id={`${id}-hint`}>{hint}</p>}
    {error && <p className="field-error" id={`${id}-error`}>{error}</p>}
  </div>;
}
