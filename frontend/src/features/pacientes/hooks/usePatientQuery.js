import { useEffect, useState } from 'react';
import { consultarPaciente, listarPacientes } from '../services/paciente.service';
import { describirErrorPaciente } from '../utils/patientError';
import { consultarMiPerfil } from '../services/perfil.service';

export function usePatientQuery(kind, parameters) {
  const key = JSON.stringify([kind, parameters]);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState(null);
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const [type, args] = JSON.parse(key);
    const request = type === 'profile' ? consultarMiPerfil(controller.signal) : type === 'list' ? listarPacientes(args, controller.signal) : consultarPaciente(args, controller.signal);
    request.then(data => {
      if (active) setResult({ key, revision, data });
    }, error => {
      if (active) setResult({ key, revision, failure: describirErrorPaciente(error, { operation: type === 'profile' ? 'profile' : 'create' }) });
    });
    return () => { active = false; controller.abort(); };
  }, [key, revision]);
  const current = result?.key === key && result.revision === revision ? result : null;
  return { data: current?.data, failure: current?.failure, loading: !current, reload: () => setRevision(value => value + 1) };
}
