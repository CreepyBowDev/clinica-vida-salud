// El enlace nunca se expone como visor. La URL vive solo hasta el inicio de descarga o el retiro del contexto.
export function iniciarDescarga({ blob, nombre }) {
  const url = URL.createObjectURL(blob), link = document.createElement('a');
  let timer, retired = false;
  const retirar = () => { if (retired) return; retired = true; clearTimeout(timer); URL.revokeObjectURL(url); link.remove(); };
  try {
    link.href = url; link.download = nombre; link.hidden = true;
    document.body.append(link); link.click(); link.remove();
    timer = setTimeout(retirar, 60000);
    return retirar;
  } catch (error) { retirar(); throw error; }
}
