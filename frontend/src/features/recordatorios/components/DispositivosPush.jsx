import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion, obtenerEpocaSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import Button from '../../../components/Button';
import ActionGroup from '../../../components/ActionGroup';
import ConfirmationPanel from '../../../components/ConfirmationPanel';
import Pagination from '../../../components/Pagination';
import AltaPush from './AltaPush';
import { comprobarSesionPush, listarSuscripcionesPush, revocarSuscripcionPush } from '../services/push.service';
import { desuscribirNavegador } from '../utils/pushBrowser';
import { formatearInstanteCita } from '../../agenda/utils/agendaFormat';

function errorBaja(error) {
  return ({ 401: 'La sesión terminó. Inicia sesión para continuar.', 403: 'No tienes permiso para gestionar estos dispositivos.',
    404: 'El dispositivo ya no está accesible. Actualiza la lista.', 409: 'La suscripción cambió. Actualiza la lista.',
    419: 'La protección de la sesión caducó. Comprueba tu sesión antes de volver a intentarlo.',
    422: 'No se pudo validar la solicitud.', 429: 'Demasiados intentos. Espera antes de volver a intentarlo.' })[error.response?.status]
    || 'No se pudo confirmar la revocación en el servidor. El resultado puede ser incierto; actualiza la lista antes de volver a intentarlo.';
}

export default function DispositivosPush() {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const enabled = status === 'authenticated' && usuario?.rol === ROLES.PACIENTE;
  const identityRef = useRef(identity); identityRef.current = identity;
  const pending = useRef(null), busy = useRef(false);
  const confirmButton = useRef(null), trigger = useRef(null), heading = useRef(null), hadTarget = useRef(false);
  const [view, setView] = useState(null), [page, setPage] = useState(1), [revision, setRevision] = useState(0);
  const [altaBusy, setAltaBusy] = useState(false), [altaRevision, setAltaRevision] = useState(0);
  const current = view?.identity === identity && view.epoca === obtenerEpocaSesion() ? view : null;
  const reload = () => { setRevision(v => v + 1); };

  useEffect(() => {
    if (current?.target) confirmButton.current?.focus();
    else if (hadTarget.current) {
      // El control de confirmación desaparece; no dejar el foco en el body.
      const destination = trigger.current?.isConnected && !trigger.current.disabled ? trigger.current : heading.current;
      destination?.focus();
    }
    hadTarget.current = Boolean(current?.target);
  }, [current?.target]);

  useEffect(() => {
    // Solo cancelar trabajo/retirar datos: ni logout, ni expiración, ni pagehide hacen bajas aquí.
    const clear = () => { pending.current?.abort(); busy.current = false; setView(null); setAltaBusy(false); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); pending.current?.abort(); };
  }, []);
  useEffect(() => {
    setAltaBusy(false);
    if (!enabled) return;
    const controller = new AbortController(), epoca = obtenerEpocaSesion(); pending.current = controller;
    const valid = () => !controller.signal.aborted && identityRef.current === identity && epoca === obtenerEpocaSesion();
    setView(v => ({ identity, epoca, loading: true, message: v?.identity === identity && v.epoca === epoca ? v.message : null }));
    listarSuscripcionesPush(page, controller.signal, epoca).then(data => {
      if (valid()) setView(v => ({ ...v, data, loading: false }));
    }, () => { if (valid()) setView(v => ({ ...v, loading: false, error: 'No pudimos consultar tus dispositivos. Puedes actualizar la lista.' })); });
    return () => { controller.abort(); pending.current?.abort(); busy.current = false; };
  }, [enabled, identity, page, revision]);

  function confirmar(target, event) {
    if (!enabled || busy.current || altaBusy || !current || current.loading) return;
    trigger.current = event.currentTarget;
    setView(v => ({ ...v, target, message: null }));
  }
  async function revocar() {
    const target = current?.target;
    if (!target || !enabled || busy.current || altaBusy) return;
    const controller = new AbortController(), epoca = obtenerEpocaSesion();
    pending.current?.abort(); pending.current = controller; busy.current = true;
    const comprobar = () => {
      comprobarSesionPush(epoca, controller.signal);
      if (identityRef.current !== identity) throw new Error('El titular cambió.');
    };
    setView(v => ({ ...v, busy: true, target: null }));
    let message;
    try {
      comprobar();
      await revocarSuscripcionPush(target.actual ? null : target.id_suscripcion, controller.signal, epoca);
      comprobar();
      if (target.actual) {
        try {
          await desuscribirNavegador(comprobar);
          comprobar();
          message = 'Revocación confirmada en el servidor y baja local confirmada en este navegador. El permiso del navegador no se modifica.';
        } catch {
          comprobar();
          message = 'Revocación confirmada en el servidor, pero no se pudo confirmar la baja local. Puedes volver a desactivar este navegador para reintentarlo.';
        }
      } else message = 'Revocación confirmada en el servidor. No se ha modificado el navegador del otro dispositivo.';
    } catch (error) {
      if (!controller.signal.aborted && identityRef.current === identity && epoca === obtenerEpocaSesion()) {
        message = `${errorBaja(error)}${target.actual ? ' No se intentó la baja local.' : ''}`;
      }
    } finally {
      if (pending.current === controller) busy.current = false;
    }
    if (controller.signal.aborted || identityRef.current !== identity || epoca !== obtenerEpocaSesion()) return;
    setView(v => ({ ...v, busy: false, message }));
    if (target.actual) setAltaRevision(v => v + 1); // Retirar una confirmación de alta anterior, sin reautorizar.
    reload();
  }

  if (!enabled) return null;
  const locked = altaBusy || current?.busy;
  return <>
    <AltaPush key={`${identity}:${altaRevision}`} bloqueado={Boolean(current?.busy || current?.target)} onOcupado={setAltaBusy} onAutorizado={reload} />
    <section aria-label="Mis dispositivos push">
      <h2 ref={heading} tabIndex={-1}>Mis dispositivos</h2>
      <p>Solo tus suscripciones. El servidor no facilita nombres de equipos; se identifican por su número y por si corresponden a este navegador. Los avisos internos no se desactivan.</p>
      <p>La baja no retira notificaciones ya transmitidas. Revocar otro dispositivo no elimina su suscripción local ni cambia su permiso.</p>
      <ActionGroup>
        <Button variant="secondary" disabled={locked || current?.loading} onClick={reload}>Actualizar dispositivos</Button>
        <Button variant="secondary" disabled={locked || !current || current.loading} onClick={event => confirmar({ actual: true }, event)}>Desactivar este navegador</Button>
      </ActionGroup>
      {current?.message && <p role="status">{current.message}</p>}
      {current?.loading && <p role="status">Consultando dispositivos…</p>}
      {current?.error && <p role="alert">{current.error}</p>}
      {current?.busy && <p role="status">Revocando dispositivo…</p>}
      {current?.target && <ConfirmationPanel title={current.target.actual ? '¿Desactivar este navegador?' : `¿Revocar el dispositivo ${current.target.id_suscripcion}?`}>
        <p>{current.target.actual ? 'Se revocará la autorización del servidor y después se intentará la baja local.' : 'Se revocará únicamente la autorización de este dispositivo en tu cuenta.'} Para volver a autorizar se necesitará una acción explícita.</p>
        <ActionGroup><Button ref={confirmButton} disabled={locked} onClick={revocar}>Confirmar revocación</Button>
          <Button variant="quiet" disabled={locked} onClick={() => setView(v => ({ ...v, target: null }))}>Conservar dispositivo</Button></ActionGroup>
      </ConfirmationPanel>}
      {current?.data && <>
        {current.data.data.length === 0 && <p>No hay suscripciones en esta página.</p>}
        <ul>{current.data.data.map(s => <li key={s.id_suscripcion}>
          <h3>Dispositivo {s.id_suscripcion}{s.actual ? ' · Este navegador' : ''}</h3>
          <p>Estado: {s.activa ? 'Activo' : 'Inactivo'} · Versión: {s.version}</p>
          <p>Autorizado: <time dateTime={s.autorizada_en}>{formatearInstanteCita(s.autorizada_en)}</time></p>
          {s.revocada_en && <p>Revocado: <time dateTime={s.revocada_en}>{formatearInstanteCita(s.revocada_en)}</time></p>}
          {!s.actual && <Button variant="quiet" disabled={locked || Boolean(current.target) || s.revocada_en !== null} onClick={event => confirmar(s, event)}>Revocar dispositivo {s.id_suscripcion}</Button>}
        </li>)}</ul>
        {!locked && !current.target && current.data.meta.total > 0 && <Pagination meta={current.data.meta} label="dispositivos" onPage={setPage} />}
      </>}
    </section>
  </>;
}
