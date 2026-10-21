import Alert from '../../../components/Alert';

export default function DoctorFailure({ failure, remaining = 0 }) {
  if (!failure) return null;
  return <Alert tabIndex="-1"><p>{failure.status === 404 ? 'No se encontró el médico solicitado.' : failure.message}</p>{remaining > 0 && <p>Espera {remaining} segundos antes de volver a intentarlo.</p>}</Alert>;
}
