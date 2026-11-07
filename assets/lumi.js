/* LUMI · Chat por texto; Gemini se consulta exclusivamente desde PHP. */
(() => {
    'use strict';
    const raiz = document.getElementById('lumi-raiz');
    if (!raiz || raiz.dataset.lumiInicializada === 'true') return;
    raiz.dataset.lumiInicializada = 'true';

    const refs = {
        boton: document.getElementById('lumi-botón'),
        panel: document.getElementById('lumi-panel'),
        dialogo: document.getElementById('lumi-dialogo'),
        titulo: document.querySelector('.lumi-cabecera h2'),
        cerrar: document.getElementById('lumi-cerrar'),
        nueva: document.getElementById('lumi-nueva'),
        log: document.getElementById('lumi-mensajes'),
        aviso: document.getElementById('lumi-aviso'),
        formulario: document.getElementById('lumi-envio'),
        entrada: document.getElementById('lumi-entrada'),
        enviar: document.querySelector('#lumi-envio button[type="submit"]'),
    };

    const claveVista = 'cvs-lumi-vista:' + new URL('.', location.href).pathname;
    const leerVista = () => {
        try {
            const dato = JSON.parse(sessionStorage.getItem(claveVista));
            return {abierto: dato?.abierto === true, scroll: Math.max(0, Math.min(100000, Number(dato?.scroll) || 0)),
                dialogo: Math.max(0, Math.min(100000, Number(dato?.dialogo) || 0))};
        } catch (_) { return {abierto: false, scroll: 0, dialogo: 0}; }
    };
    let vista = leerVista();
    const chatDisponible = () => Boolean(estado.datos?.habilitada || estado.datos?.localDisponible);
    const estado = { datos: null, ocupada: false, carga: null, cargando: false, saludada: false, restaurando: false, aperturaExplicita: false, saliendo: false, revision: 0 };
    const borrarVista = () => { try { sessionStorage.removeItem(claveVista); } catch (_) {} vista = {abierto: false, scroll: 0, dialogo: 0}; };
    const guardarVista = () => {
        if (!estado.datos || estado.restaurando) return;
        vista = {abierto: raiz.dataset.lumiAbierto === 'true', scroll: refs.log.scrollTop, dialogo: refs.dialogo.scrollTop};
        try { sessionStorage.setItem(claveVista, JSON.stringify(vista)); } catch (_) { /* La sesión PHP conserva los mensajes igualmente. */ }
    };
    const voz = window.CvsLumiVoz?.({
        raiz, entrada: refs.entrada, enviar: refs.enviar,
        disponible: () => chatDisponible() && !estado.ocupada,
        activable: () => chatDisponible() && !estado.cargando,
        estadoBase: () => estado.ocupada ? 'procesando' : 'activa',
        abrirPanel: () => { estado.aperturaExplicita = true; abrir(false); cargarEstado(); },
    });

    const abrir = (enfocar = true) => {
        raiz.dataset.lumiAbierto = 'true';
        raiz.dataset.lumiEstado = estado.ocupada ? 'procesando' : 'activa';
        refs.panel.inert = false;
        refs.panel.setAttribute('aria-hidden', 'false');
        refs.boton.setAttribute('aria-expanded', 'true');
        voz?.sincronizar();
        guardarVista();
        requestAnimationFrame(() => {
            if (enfocar && raiz.dataset.lumiAbierto === 'true') {
                (refs.entrada.disabled ? refs.cerrar : refs.entrada).focus();
            }
        });
    };

    const cerrar = (enfocar = true) => {
        raiz.dataset.lumiAbierto = 'false';
        voz?.detener();
        raiz.dataset.lumiEstado = 'silencio';
        refs.boton.setAttribute('aria-expanded', 'false');
        if (enfocar) refs.boton.focus();
        refs.panel.inert = true;
        refs.panel.setAttribute('aria-hidden', 'true');
        guardarVista();
    };

    const burbuja = (role, texto, lectura = false) => {
        const li = document.createElement('li');
        li.className = role === 'usuario' ? 'lumi-msj-usuario' : 'lumi-msj-lumi';
        li.textContent = texto;
        refs.log.append(li);
        if (lectura && role === 'lumi') voz?.agregarLectura(li, texto);
        refs.log.scrollTop = refs.log.scrollHeight;
        return li;
    };

    const estadoProcesando = () => {
        const li = document.createElement('li');
        li.className = 'lumi-msj-lumi';
        li.setAttribute('role', 'status');
        li.setAttribute('aria-label', 'Lumi está escribiendo');
        li.innerHTML = '<span class="lumi-pensar" aria-hidden="true"><span></span><span></span><span></span></span>';
        refs.log.append(li);
        refs.log.scrollTop = refs.log.scrollHeight;
        return li;
    };

    const terminar = (li) => li && li.parentNode && li.parentNode.removeChild(li);

    const agregarNavegacion = (mensaje, datos) => {
        if (datos.origen !== 'guia' || !Array.isArray(datos.navegacion)) return;
        const permitidas = new Map((estado.datos.funciones || []).map(funcion => [funcion.id, funcion.titulo]));
        const grupo = document.createElement('div');
        grupo.className = 'lumi-destinos';
        grupo.setAttribute('aria-label', 'Secciones disponibles');
        const usados = new Set();
        for (const funcion of datos.navegacion) {
            const id = funcion?.id;
            if (typeof id !== 'string' || !/^[a-z_]{1,32}$/.test(id) || !permitidas.has(id) || usados.has(id)) continue;
            usados.add(id);
            const enlace = document.createElement('a');
            // No se usan URLs ni títulos proporcionados por Gemini.
            enlace.href = 'lumi_api.php?accion=ir&destino=' + encodeURIComponent(id);
            enlace.textContent = 'Abrir ' + permitidas.get(id);
            enlace.addEventListener('click', () => {
                voz?.detener();
                raiz.dataset.lumiAbierto = 'true';
                guardarVista();
            });
            grupo.append(enlace);
        }
        if (!grupo.childElementCount) return;
        const origen = document.createElement('small');
        origen.className = 'lumi-guia-origen';
        origen.textContent = 'Guía del sistema';
        mensaje.append(origen, grupo);
        refs.log.scrollTop = refs.log.scrollHeight;
    };

    const restaurar = (conversacion, conservarPosicion = false) => {
        if (!Array.isArray(conversacion?.mensajes)) return false;
        const posicion = conservarPosicion ? {...vista} : {scroll: Number.MAX_SAFE_INTEGER, dialogo: refs.dialogo.scrollTop};
        estado.restaurando = true;
        voz?.limpiarLecturas?.();
        refs.log.replaceChildren();
        for (const m of conversacion.mensajes.slice(0, 25)) {
            if (!['usuario', 'lumi'].includes(m.rol) || typeof m.contenido !== 'string') continue;
            const elemento = burbuja(m.rol, m.contenido, m.rol === 'lumi' && ['gemini', 'guia'].includes(m.origen));
            if (m.rol === 'lumi') agregarNavegacion(elemento, m);
        }
        estado.saludada = refs.log.childElementCount > 0;
        requestAnimationFrame(() => {
            refs.log.scrollTop = posicion.scroll;
            refs.dialogo.scrollTop = posicion.dialogo;
            estado.restaurando = false;
            guardarVista();
        });
        return true;
    };

    const invalidar = (conservarPreferenciaVoz = false) => {
        estado.revision++;
        if (conservarPreferenciaVoz) voz?.suspenderDespertar?.();
        else voz?.detenerDespertar?.(); // Cerrar sesión o cambiar de cuenta revoca el consentimiento.
        voz?.detener();
        voz?.limpiarLecturas?.();
        estado.datos = null;
        estado.carga = null;
        estado.cargando = false;
        estado.ocupada = false;
        estado.saludada = false;
        cerrar(false);
        refs.log.replaceChildren();
        refs.entrada.value = '';
        refs.entrada.disabled = true;
        refs.enviar.disabled = true;
        refs.nueva.disabled = true;
        borrarVista();
    };

    const cargarEstado = (forzar = false) => {
        if (forzar && !estado.ocupada && !estado.cargando) estado.carga = null;
        if (estado.carga) return estado.carga;
        estado.cargando = true;
        const revision = estado.revision;
        estado.carga = fetch('lumi_api.php?accion=estado', { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((respuesta) => (respuesta.ok ? respuesta.json() : Promise.reject(respuesta.status)))
            .then(async (datos) => {
                if (estado.saliendo || revision !== estado.revision) return;
                const anterior = estado.datos;
                if ((anterior && anterior.csrf !== datos.csrf) || (datos.conversacion && !datos.conversacion.reanudada)) {
                    voz?.detenerDespertar?.();
                    voz?.detener();
                }
                await voz?.validarSesion?.(datos.csrf);
                if (estado.saliendo || revision !== estado.revision) return;
                estado.datos = datos;
                if (datos.conversacion && !datos.conversacion.reanudada) {
                    borrarVista();
                    if (anterior) cerrar(false);
                }
                const volverAbierta = vista.abierto && datos.conversacion?.reanudada;
                restaurar(datos.conversacion, true);
                aplicarEstado();
                if (volverAbierta && !estado.aperturaExplicita) abrir(false);
            })
            .catch(() => {
                if (estado.saliendo || revision !== estado.revision) return; // Ignorar respuestas de una sesión invalidada o página abandonada.
                invalidar();
                estado.datos = { habilitada: false, aviso: 'La asistente no está disponible desde esta página. Vuelve a iniciar sesión o intenta más tarde.' };
                aplicarEstado();
            }).finally(() => {
                if (revision !== estado.revision) return;
                estado.cargando = false;
                if (!estado.saliendo) voz?.sincronizar();
            });
        return estado.carga;
    };

    const aplicarEstado = () => {
        const datos = estado.datos;
        refs.nueva.disabled = !datos.csrf || estado.ocupada;
        refs.titulo.textContent = 'Asistente ' + (datos.nombre || 'Lumi');
        const privacidad = document.getElementById('lumi-privacidad-texto');
        if (!chatDisponible()) {
            // Aviso breve solo cuando el servicio no funciona; sin pancartas permanentes.
            refs.aviso.textContent = datos.aviso || 'La asistente no está disponible en este momento.';
            refs.aviso.hidden = false;
            refs.panel.dataset.sinServicio = 'true';
            refs.entrada.disabled = true;
            refs.enviar.disabled = true;
            refs.entrada.placeholder = 'Servicio no habilitado por la clínica';
            voz?.detener();
            voz?.detenerDespertar?.();
            return;
        }
        if (privacidad) privacidad.textContent = datos.privacidad || '';
        refs.entrada.disabled = false;
        refs.enviar.disabled = false;
        refs.panel.dataset.sinServicio = 'false';
        refs.aviso.hidden = true;
        if (!estado.saludada) {
            burbuja('lumi', datos.saludo || '¿En qué puedo ayudarte hoy?');
            estado.saludada = true;
        }
        refs.entrada.placeholder = 'Escribe tu consulta…';
        voz?.sincronizar();
    };

    const enviarMensaje = () => {
        if (estado.ocupada || voz?.escuchando()) return;
        const texto = refs.entrada.value.trim();
        if (!estado.datos || !chatDisponible() || texto === '') return;
        const maximo = estado.datos.maxMensaje || 700;
        if (texto.length > maximo) {
            burbuja('lumi', 'Esa consulta es demasiado extensa. Exprésala en pocas palabras.');
            return;
        }
        estado.ocupada = true;
        const revision = estado.revision;
        refs.nueva.disabled = true;
        voz?.detener();
        raiz.dataset.lumiEstado = 'procesando';
        refs.enviar.disabled = true;
        burbuja('usuario', texto);
        refs.entrada.value = '';
        refs.entrada.style.height = '';
        const pendiente = estadoProcesando();
        const cuerpo = { csrf: estado.datos.csrf, mensaje: texto };
        const avisoRed = 'La asistente no está disponible en este momento. Intenta nuevamente más tarde.';
        fetch('lumi_api.php?accion=mensaje', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo),
        }).then(async (respuesta) => {
            const datos = await respuesta.json().catch(() => ({}));
            if (revision !== estado.revision || estado.saliendo) return;
            if (respuesta.status === 401 || respuesta.status === 403) { invalidar(); return; }
            if (respuesta.ok && datos.estado === 'ok' && typeof datos.texto === 'string' && datos.texto.trim() !== '') {
                terminar(pendiente);
                if (!restaurar(datos.conversacion)) {
                    const mensaje = burbuja('lumi', datos.texto, true);
                    agregarNavegacion(mensaje, datos);
                }
            } else if (respuesta.ok && datos.habilitada === false) {
                estado.datos.habilitada = false;
                estado.datos.aviso = datos.aviso;
                aplicarEstado();
                terminar(pendiente);
                burbuja('lumi', datos.aviso || 'La asistente inteligente todavía no está activada.');
            } else {
                terminar(pendiente);
                if (!restaurar(datos.conversacion)) burbuja('lumi', datos.error || datos.aviso || avisoRed);
            }
        }).catch(() => {
            if (revision !== estado.revision || estado.saliendo) return;
            terminar(pendiente);
            burbuja('lumi', avisoRed);
        }).finally(() => {
            if (revision !== estado.revision || estado.saliendo) return;
            estado.ocupada = false;
            raiz.dataset.lumiEstado = raiz.dataset.lumiAbierto === 'true' ? 'activa' : 'silencio';
            if (raiz.dataset.lumiAbierto === 'true' && refs.panel.contains(document.activeElement)) refs.entrada.focus();
            if (chatDisponible()) refs.enviar.disabled = false;
            refs.nueva.disabled = !estado.datos?.csrf;
            voz?.sincronizar();
            guardarVista();
        });
    };

    refs.boton.addEventListener('click', () => {
        estado.aperturaExplicita = true;
        if (raiz.dataset.lumiAbierto === 'true') {
            cerrar();
        } else {
            abrir();
            cargarEstado();
        }
    });
    refs.cerrar.addEventListener('click', cerrar);
    refs.nueva.addEventListener('click', async () => {
        if (estado.ocupada || !estado.datos?.csrf) return;
        if (refs.log.querySelector('.lumi-msj-usuario') && !window.confirm('¿Comenzar una nueva conversación y borrar los mensajes actuales de Lumi?')) return;
        voz?.detener();
        estado.ocupada = true;
        const revision = estado.revision;
        voz?.sincronizar();
        refs.nueva.disabled = true;
        try {
            const respuesta = await fetch('lumi_api.php?accion=nueva', {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: estado.datos.csrf})});
            if (revision !== estado.revision || estado.saliendo) return;
            if (respuesta.status === 401 || respuesta.status === 403) { invalidar(); return; }
            if (!respuesta.ok) throw new Error('No disponible');
            const datos = await respuesta.json();
            if (revision !== estado.revision || estado.saliendo) return;
            refs.entrada.value = '';
            restaurar(datos.conversacion);
        } catch (_) { if (revision === estado.revision && !estado.saliendo) burbuja('lumi', 'No se pudo reiniciar la conversación. Intenta nuevamente.'); }
        finally {
            if (revision !== estado.revision || estado.saliendo) return;
            estado.ocupada = false;
            refs.nueva.disabled = !estado.datos?.csrf;
            voz?.sincronizar();
        }
    });
    refs.panel.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape') {
            evento.preventDefault();
            cerrar();
        }
    });
    refs.formulario.addEventListener('submit', (evento) => {
        evento.preventDefault();
        enviarMensaje();
    });
    refs.entrada.addEventListener('input', () => {
        refs.entrada.style.height = '';
        refs.entrada.style.height = Math.min(refs.entrada.scrollHeight, 120) + 'px';
    });
    refs.log.addEventListener('scroll', guardarVista, {passive: true});
    refs.dialogo.addEventListener('scroll', guardarVista, {passive: true});
    window.addEventListener('pagehide', () => {
        estado.saliendo = true;
        voz?.detener(); voz?.limpiarLecturas?.(); guardarVista();
        // Evitar conversaciones antiguas en instantáneas de la caché atrás/adelante.
        refs.log.replaceChildren(); estado.saludada = false;
    });
    window.addEventListener('pageshow', evento => { estado.saliendo = false; if (evento.persisted) { invalidar(true); cargarEstado(true); } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !estado.saliendo && !estado.ocupada) cargarEstado(true); });
    const canal = typeof BroadcastChannel === 'function' ? new BroadcastChannel('cvs-lumi-sesion') : null;
    if (canal) canal.onmessage = evento => { if (evento.data === 'salida') invalidar(); };
    document.addEventListener('submit', evento => {
        if (evento.target instanceof HTMLFormElement && new URL(evento.target.action, location.href).pathname.endsWith('/logout.php')) {
            invalidar();
            canal?.postMessage('salida');
        }
    });
    // Cada documento revalida antes de mostrar mensajes. GET estado nunca llama a Gemini.
    cargarEstado();
})();
