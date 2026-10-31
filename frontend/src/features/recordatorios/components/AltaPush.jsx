import { useEffect, useRef, useState } from 'react';
import { escucharCambioSesion, obtenerEpocaSesion } from '../../../api/api';
import { useAuth } from '../../../hooks/useAuth';
import { ROLES } from '../../../constants/roles';
import Button from '../../../components/Button';
import { autorizarPush, clavePush, comprobarSesionPush, consultarConfiguracionPush } from '../services/push.service';
import { soportePush, suscribirNavegador } from '../utils/pushBrowser';

function errorAlta(error) {
  const status = error.response?.status, code = error.response?.data?.error?.code;
  if (code === 'PUSH_NO_CONFIGURADO') return 'El canal de notificaciones del dispositivo está deshabilitado.';
  if (status === 409) return 'La suscripción cambió o está obsoleta. No se confirmó el alta; requiere revisión del dispositivo.';
  return ({ 401: 'La sesión terminó. Inicia sesión para continuar.', 403: 'No tienes permiso para autorizar este dispositivo.',
    419: 'La protección de la sesión caducó. Comprueba tu sesión antes de volver a autorizar.',
    422: 'No se pudo validar la suscripción del navegador.', 429: 'Demasiados intentos. Espera antes de volver a autorizar.' })[status]
    || 'No se pudo confirmar el alta. El permiso del navegador no demuestra autorización en el servidor; un fallo de conexión puede dejar un resultado incierto.';
}

export default function AltaPush({ bloqueado = false, onOcupado, onAutorizado }) {
  const { status, usuario } = useAuth();
  const identity = JSON.stringify([status, usuario?.id_usuario, usuario?.rol]);
  const enabled = status === 'authenticated' && usuario?.rol === ROLES.PACIENTE;
  const identityRef = useRef(identity); identityRef.current = identity;
  const pending = useRef(null), busy = useRef(false);
  const [view, setView] = useState(null), [revision, setRevision] = useState(0);
  const incompatible = soportePush();
  const current = view?.identity === identity && view.epoca === obtenerEpocaSesion() ? view : null;

  useEffect(() => {
    const clear = () => { pending.current?.abort(); busy.current = false; setView(null); };
    const unsubscribe = escucharCambioSesion(clear);
    window.addEventListener('pagehide', clear);
    return () => { unsubscribe(); window.removeEventListener('pagehide', clear); pending.current?.abort(); };
  }, []);
  useEffect(() => {
    if (!enabled || incompatible) return;
    const controller = new AbortController(), epoca = obtenerEpocaSesion(); pending.current = controller;
    setView({ identity, epoca, loading: true });
    consultarConfiguracionPush(controller.signal, epoca).then(config => {
      if (!controller.signal.aborted && identityRef.current === identity && epoca === obtenerEpocaSesion()) setView({ identity, epoca, config });
    }, () => {
      if (!controller.signal.aborted && identityRef.current === identity && epoca === obtenerEpocaSesion()) setView({ identity, epoca, message: 'No pudimos consultar la configuración de notificaciones.' });
    });
    return () => { controller.abort(); pending.current?.abort(); busy.current = false; };
  }, [identity, enabled, incompatible, revision]);

  async function activar() {
    if (!enabled || incompatible || bloqueado || busy.current || !current?.config?.habilitado) return;
    const epoca = obtenerEpocaSesion(), controller = new AbortController();
    pending.current?.abort(); pending.current = controller; busy.current = true; onOcupado?.(true);
    const comprobar = () => {
      comprobarSesionPush(epoca, controller.signal);
      if (identityRef.current !== identity) throw new Error('El titular cambió.');
    };
    setView({ identity, epoca, config: current.config, busy: true });
    try {
      // Sin await previo: conservar la activación de usuario exigida por navegadores móviles.
      const permission = await Notification.requestPermission();
      comprobar();
      if (permission !== 'granted') {
        setView({ identity, epoca, config: current.config, message: permission === 'denied'
          ? 'Permiso denegado. Puedes revisarlo en la configuración del navegador.' : 'No concediste permiso. No se registró el dispositivo.' });
        return;
      }
      const subscription = await suscribirNavegador(clavePush(current.config.clave_publica_vapid), controller.signal, comprobar);
      comprobar();
      await autorizarPush(subscription, controller.signal, epoca);
      comprobar();
      setView({ identity, epoca, config: current.config, confirmed: true });
      onAutorizado?.();
    } catch (error) {
      if (!controller.signal.aborted && identityRef.current === identity && epoca === obtenerEpocaSesion()) {
        setView({ identity, epoca, config: current.config, message: errorAlta(error) });
      }
    } finally {
      if (pending.current === controller) {
        busy.current = false;
        if (!controller.signal.aborted && identityRef.current === identity && epoca === obtenerEpocaSesion()) onOcupado?.(false);
      }
    }
  }

  if (!enabled) return null;
  const permission = incompatible ? null : Notification.permission;
  return <section aria-label="Notificaciones de este dispositivo">
    <h2>Notificaciones de este dispositivo</h2>
    <p>Opcionales. Los avisos internos siguen disponibles. El alta no acredita entrega ni lectura. Al abrir una notificación se consulta la información vigente con tu sesión; la notificación puede estar obsoleta.</p>
    {incompatible ? <p>{incompatible}</p> : <>
      <p>Permiso del navegador: {({ granted: 'concedido', denied: 'denegado', default: 'sin conceder' })[permission] || 'desconocido'}.</p>
      <p role="status">Autorización en el servidor: {current?.confirmed ? 'confirmada para esta sesión y dispositivo.' : 'no comprobada; conceder permiso no confirma el alta.'}</p>
      {current?.loading && <p>Consultando configuración de notificaciones…</p>}
      {!current?.loading && !current?.config && <Button variant="quiet" onClick={() => setRevision(v => v + 1)}>Consultar configuración del dispositivo</Button>}
      {current?.config?.habilitado === false && <p>El canal de notificaciones del dispositivo está deshabilitado.</p>}
      {permission === 'denied' && <p>Revisa el permiso en la configuración del navegador si deseas habilitarlo.</p>}
      <Button variant="secondary" onClick={activar} disabled={bloqueado || !current?.config?.habilitado || current?.busy || current?.confirmed || permission === 'denied'}>
        {current?.busy ? 'Autorizando dispositivo…' : 'Autorizar notificaciones en este dispositivo'}
      </Button>
      {current?.message && <p role="status">{current.message}</p>}
    </>}
  </section>;
}
