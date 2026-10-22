import { useId } from 'react';

export default function FormField({ label, error, hint, trailing, id: providedId, 'aria-describedby': externalDescription, ...inputProps }) {
  const generatedId = useId();
  const id = providedId ?? generatedId;
  const describedBy = [externalDescription, hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ');
  return <div className="field">
    <label htmlFor={id}>{label}</label>
    <div className={`field-control ${trailing ? 'field-control--trailing' : ''}`}>
      <input {...inputProps} id={id} aria-invalid={Boolean(error)} aria-describedby={describedBy || undefined} />
      {trailing}
    </div>
    {hint && <p className="field-hint" id={`${id}-hint`}>{hint}</p>}
    {error && <p className="field-error" id={`${id}-error`}>{error}</p>}
  </div>;
}
