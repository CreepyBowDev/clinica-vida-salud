import { useState } from 'react';

// Conserva el formulario visitado al cambiar solo la presentación. Nunca conserva datos fuera de memoria.
export default function OperationDraft({ active, children, onDirty }) {
  const [visited, setVisited] = useState(active);
  if (active && !visited) setVisited(true);
  if (!active && !visited) return null;
  return <div hidden={!active} inert={!active} onChangeCapture={onDirty} onSubmitCapture={onDirty}>
    <fieldset disabled={!active} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>{children}</fieldset>
  </div>;
}
