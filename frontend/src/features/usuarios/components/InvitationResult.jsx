import Alert from '../../../components/Alert';

const states = {
  simulado: ['info', 'Invitación simulada. No se envió un correo externo.'],
  aceptado: ['success', 'El proveedor aceptó el correo de invitación. Esto no confirma su entrega al buzón.'],
  fallido: ['warning', 'No se confirmó el envío. La cuenta permanece pendiente; puedes solicitar otro envío desde su detalle.'],
  pendiente: ['info', 'El envío está pendiente de confirmación. Consulta el detalle de la cuenta antes de solicitar otro envío.'],
};

export default function InvitationResult({ result }) {
  if (!result) return null;
  const invitation = result.meta?.invitacion;
  const [variant, message] = states[invitation?.envio] || ['warning', 'La cuenta fue confirmada, pero no disponemos de confirmación de envío. Consulta su detalle.'];
  return <Alert variant={variant}><p>{message}</p><p>El enlace es de un solo uso y tiene una vigencia de 24 horas. Un nuevo envío invalida el anterior.</p></Alert>;
}
