import { useEffect, useId, useRef } from 'react';
import Icon from './Icon';

export default function Modal({ open, onClose, title, children, closeLabel = 'Cerrar ayuda' }) {
  const ref = useRef(null);
  const titleId = useId();
  useEffect(() => {
    const dialog = ref.current;
    if (!open) return;
    const previous = document.activeElement;
    dialog.showModal();
    document.body.classList.add('modal-open');
    return () => {
      dialog.close();
      document.body.classList.remove('modal-open');
      previous?.focus();
    };
  }, [open]);

  function containFocus(event) {
    if (event.key !== 'Tab') return;
    const controls = [...ref.current.querySelectorAll('button, a[href], input, select, textarea, [tabindex]')]
      .filter(control => !control.matches(':disabled') && control.tabIndex >= 0 && control.type !== 'hidden' && !control.closest('[hidden]'));
    const first = controls[0];
    const last = controls.at(-1);
    if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
      event.preventDefault();
      (event.shiftKey ? last : first)?.focus();
    }
  }

  return <dialog ref={ref} className="modal" aria-labelledby={titleId}
    onKeyDown={containFocus}
    onCancel={(event) => { event.preventDefault(); onClose(); }} onClose={onClose}>
    <div className="modal-heading"><h2 id={titleId}>{title}</h2>
      <button type="button" className="icon-button" aria-label={closeLabel} onClick={onClose}><Icon name="close" /></button>
    </div>
    {children}
  </dialog>;
}
