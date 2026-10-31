import { api, csrfURL, obtenerEpocaSesion } from '../../../api/api';
import { ROLES } from '../../../constants/roles';
import { esIdentificador } from '../../usuarios/services/usuario.service';
import { esInstanteCita } from '../../agenda/utils/agendaFormat';
import { proyectarConsulta } from './historiaClinica.service';

export const camposAlta = { tipo_consulta: 16, motivo_consulta: 4000, sintomas: 10000, observaciones: 10000, diagnostico: 10000, indicaciones_tratamiento: 10000 };
export const camposAntecedentesAlta = { tipo_sangre: 16, alergias: 10000, enfermedades_cronicas: 10000, medicamentos: 10000, enfermedades_familiares: 10000, antecedentes_relevantes: 10000 };
function exigir(value) { if (!value) throw new Error('No se pudo verificar el registro de consulta.'); }

export function prepararAlta({ contexto, consulta, antecedentes = {}, declaracion }) {
  const o = contexto?.edicion?.originales;
  exigir(contexto?.consulta === null && contexto.edicion?.acciones.registrar === true && contexto.edicion.impedimentos.registrar.length === 0
    && o?.id_consulta === null && o.id_version_consulta === null && (o.id_version_antecedente === null || esIdentificador(o.id_version_antecedente))
    && Number.isInteger(o.version_programacion) && o.version_programacion > 0 && o.version_programacion <= 2147483647 && esInstanteCita(o.actualizada_en) && declaracion === true);
  const datos = {};
  for (const [campo, max] of Object.entries(camposAlta)) {
    const value = consulta?.[campo];
    if (value === undefined && !['tipo_consulta', 'motivo_consulta'].includes(campo)) continue;
    exigir((value === null && !['tipo_consulta', 'motivo_consulta'].includes(campo)) || (typeof value === 'string' && [...value].length <= max));
    datos[campo] = value;
  }
  exigir(['Control', 'Enfermedad'].includes(datos.tipo_consulta) && datos.motivo_consulta.trim().length > 0);
  const cambios = {};
  for (const [campo, max] of Object.entries(camposAntecedentesAlta)) {
    if (!Object.hasOwn(antecedentes, campo)) continue;
    const value = antecedentes[campo]; exigir(value === null || (typeof value === 'string' && [...value].length <= max)); cambios[campo] = value;
  }
  return { originales: { version_programacion: o.version_programacion, actualizada_en: o.actualizada_en,
    id_consulta: null, id_version_consulta: null, id_version_antecedente: o.id_version_antecedente },
  atencion_real_verificada: true, consulta: datos, ...(Object.keys(cambios).length ? { antecedentes: cambios } : {}) };
}

export async function registrarConsulta({ id, rol, idActor, ...entrada }, signal) {
  const sessionEpochExpected = entrada.contexto?.epocaSesion;
  let payload;
  try {
    exigir(Number.isInteger(sessionEpochExpected) && sessionEpochExpected === obtenerEpocaSesion()
      && esIdentificador(id) && esIdentificador(idActor) && [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol) && entrada.contexto?.cita.id_cita === id);
    payload = prepararAlta(entrada);
    signal?.throwIfAborted();
    await api.get(csrfURL, { signal, sessionBound: true, sessionEpochExpected });
    signal?.throwIfAborted();
  } catch (error) { error.consultaEnviada = false; throw error; }
  try {
    const response = await api.post(`/citas/${id}/consulta`, payload, { signal, sessionBound: true, sessionEpochExpected });
    exigir(response.status === 201);
    const result = proyectarConsulta(response.data, { id, rol });
    const v = result.consulta?.version_vigente, anterior = entrada.contexto;
    exigir(v?.numero_version === 1 && v.id_autor === idActor && v.motivo_cambio === null && result.consulta.iniciada_en === null
      && response.data.data.cita.version_programacion === payload.originales.version_programacion && response.data.data.cita.actualizada_en === payload.originales.actualizada_en
      && result.paciente.id_paciente === anterior.paciente.id_paciente && result.cita.medico.id_medico === anterior.cita.medico.id_medico
      && ['codigo_cita', 'inicio_at', 'fin_at', 'estado', 'sede_atencion', 'consultorio_atencion'].every(key => result.cita[key] === anterior.cita[key]));
    for (const campo of Object.keys(camposAlta)) exigir(v[campo] === (payload.consulta[campo] ?? null));
    let cambioAntecedente = false;
    for (const campo of Object.keys(camposAntecedentesAlta)) {
      const esperado = Object.hasOwn(payload.antecedentes ?? {}, campo) ? payload.antecedentes[campo] : anterior.antecedentes?.[campo] ?? null;
      exigir((result.antecedentes?.[campo] ?? null) === esperado);
      if (esperado !== (anterior.antecedentes?.[campo] ?? null)) cambioAntecedente = true;
    }
    if (cambioAntecedente) exigir(result.antecedentes.numero_version === (anterior.antecedentes?.numero_version ?? 0) + 1
      && result.antecedentes.id_version_antecedente !== anterior.antecedentes?.id_version_antecedente && result.antecedentes.id_autor === idActor
      && result.antecedentes.id_consulta_origen === result.consulta.id_consulta && result.antecedentes.motivo_cambio === null);
    else exigir(JSON.stringify(result.antecedentes) === JSON.stringify(anterior.antecedentes));
    return result;
  } catch (error) { error.consultaEnviada = true; throw error; }
}

export function cambiosCorreccion(contexto, consulta = {}, antecedentes = {}) {
  return {
    consulta: Object.keys(camposAlta).some(k => Object.hasOwn(consulta, k) && consulta[k] !== contexto.consulta.version_vigente[k]),
    antecedentes: Object.keys(camposAntecedentesAlta).some(k => Object.hasOwn(antecedentes, k) && antecedentes[k] !== (contexto.antecedentes?.[k] ?? null)),
  };
}

export function prepararCorreccion({ contexto, consulta = {}, antecedentes = {}, declaracion, motivo_cambio }) {
  const o = contexto?.edicion?.originales;
  exigir(contexto?.consulta?.version_vigente && contexto.edicion?.acciones.actualizar === true && contexto.edicion.impedimentos.actualizar.length === 0
    && esIdentificador(o?.id_consulta) && o.id_consulta === contexto.consulta.id_consulta && esIdentificador(o.id_version_consulta)
    && o.id_version_consulta === contexto.consulta.version_vigente.id_version_consulta
    && o.id_version_antecedente === (contexto.antecedentes?.id_version_antecedente ?? null)
    && Number.isInteger(o.version_programacion) && o.version_programacion > 0 && o.version_programacion <= 2147483647 && esInstanteCita(o.actualizada_en) && declaracion === true);
  const datos = {}, cambios = {};
  for (const [campo, max] of Object.entries(camposAlta)) {
    if (!Object.hasOwn(consulta, campo)) continue;
    const value = consulta[campo], obligatorio = ['tipo_consulta', 'motivo_consulta'].includes(campo);
    exigir((value === null && !obligatorio) || (typeof value === 'string' && [...value].length <= max && (!obligatorio || value.trim().length > 0)));
    if (campo === 'tipo_consulta') exigir(['Control', 'Enfermedad'].includes(value));
    datos[campo] = value;
  }
  for (const [campo, max] of Object.entries(camposAntecedentesAlta)) {
    if (!Object.hasOwn(antecedentes, campo)) continue;
    const value = antecedentes[campo]; exigir(value === null || (typeof value === 'string' && [...value].length <= max)); cambios[campo] = value;
  }
  const efectivo = cambiosCorreccion(contexto, datos, cambios);
  const modificado = efectivo.consulta || efectivo.antecedentes;
  if (modificado) exigir(typeof motivo_cambio === 'string' && motivo_cambio.trim().length > 0 && [...motivo_cambio].length <= 2000);
  return { originales: { version_programacion: o.version_programacion, actualizada_en: o.actualizada_en,
    id_consulta: o.id_consulta, id_version_consulta: o.id_version_consulta, id_version_antecedente: o.id_version_antecedente },
  atencion_real_verificada: true, ...(Object.keys(datos).length ? { consulta: datos } : {}), ...(Object.keys(cambios).length ? { antecedentes: cambios } : {}),
  ...(modificado ? { motivo_cambio } : {}) };
}

export async function actualizarConsulta({ id, rol, idActor, ...entrada }, signal) {
  const sessionEpochExpected = entrada.contexto?.epocaSesion;
  let payload;
  try {
    exigir(Number.isInteger(sessionEpochExpected) && sessionEpochExpected === obtenerEpocaSesion()
      && esIdentificador(id) && esIdentificador(idActor) && [ROLES.ADMINISTRADOR, ROLES.MEDICO].includes(rol) && entrada.contexto?.cita.id_cita === id);
    payload = prepararCorreccion(entrada);
    signal?.throwIfAborted(); await api.get(csrfURL, { signal, sessionBound: true, sessionEpochExpected }); signal?.throwIfAborted();
  } catch (error) { error.consultaEnviada = false; throw error; }
  try {
    const response = await api.patch(`/citas/${id}/consulta`, payload, { signal, sessionBound: true, sessionEpochExpected });
    exigir(response.status === 200 && typeof response.data?.data?.modificado === 'boolean');
    const result = proyectarConsulta(response.data, { id, rol }), anterior = entrada.contexto;
    const efectivo = cambiosCorreccion(anterior, payload.consulta, payload.antecedentes);
    exigir(response.data.data.modificado === (efectivo.consulta || efectivo.antecedentes)
      && result.paciente.id_paciente === anterior.paciente.id_paciente && result.cita.medico.id_medico === anterior.cita.medico.id_medico
      && response.data.data.cita.version_programacion === payload.originales.version_programacion && response.data.data.cita.actualizada_en === payload.originales.actualizada_en
      && ['codigo_cita', 'inicio_at', 'fin_at', 'estado', 'sede_atencion', 'consultorio_atencion'].every(k => result.cita[k] === anterior.cita[k])
      && result.consulta?.id_consulta === anterior.consulta.id_consulta && result.consulta.codigo_consulta === anterior.consulta.codigo_consulta
      && result.consulta.creada_en === anterior.consulta.creada_en && result.consulta.iniciada_en === anterior.consulta.iniciada_en);
    for (const [tipo, campos, antes, despues] of [
      ['consulta', camposAlta, anterior.consulta.version_vigente, result.consulta.version_vigente],
      ['antecedentes', camposAntecedentesAlta, anterior.antecedentes, result.antecedentes],
    ]) {
      for (const k of Object.keys(campos)) exigir((despues?.[k] ?? null) === (Object.hasOwn(payload[tipo] ?? {}, k) ? payload[tipo][k] : antes?.[k] ?? null));
      if (efectivo[tipo]) {
        const clave = tipo === 'consulta' ? 'id_version_consulta' : 'id_version_antecedente';
        exigir(despues && despues.numero_version === (antes?.numero_version ?? 0) + 1 && despues[clave] !== antes?.[clave]
          && despues.id_autor === idActor && despues.motivo_cambio === payload.motivo_cambio);
        if (tipo === 'antecedentes') exigir(despues.id_consulta_origen === anterior.consulta.id_consulta);
      } else exigir(JSON.stringify(despues) === JSON.stringify(antes));
    }
    return { ...result, modificado: response.data.data.modificado };
  } catch (error) { error.consultaEnviada = true; throw error; }
}
