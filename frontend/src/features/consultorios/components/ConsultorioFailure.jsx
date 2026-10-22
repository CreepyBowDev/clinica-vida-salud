import Alert from '../../../components/Alert';

export default function ConsultorioFailure({ failure, remaining = 0 }) {
  return failure ? <Alert tabIndex="-1"><p>{failure.status === 404 ? 'No se encontró el consultorio solicitado.' : failure.message}</p>
    {remaining > 0 && <p>Espera {remaining} segundos antes de volver a intentarlo.</p>}</Alert> : null;
}
