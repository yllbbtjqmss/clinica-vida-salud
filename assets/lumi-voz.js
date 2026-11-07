/* Voz del navegador: sin MediaRecorder, almacenamiento de audio ni cambios al backend. */
(() => {
    'use strict';
    window.CvsLumiVoz = ({raiz, entrada, enviar, disponible, estadoBase, abrirPanel, activable}) => {
        const microfono = document.getElementById('lumi-microfono');
        const detenerAudio = document.getElementById('lumi-voz-detener');
        const selector = document.getElementById('lumi-voz-seleccion');
        const aviso = document.getElementById('lumi-voz-estado');
        const consentimiento = document.getElementById('lumi-voz-consentimiento');
        const autorizar = document.getElementById('lumi-voz-autorizar');
        const posponer = document.getElementById('lumi-voz-posponer');
        const permitir = document.getElementById('lumi-voz-permitir');
        const desactivar = document.getElementById('lumi-voz-desactivar');
        const reintentar = document.getElementById('lumi-voz-reintentar');
        const reactivar = document.getElementById('lumi-voz-reactivar');
        const preferencia = document.getElementById('lumi-voz-preferencia');
        const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
        const sintesis = window.speechSynthesis;
        const puedeHablar = Boolean(sintesis && window.SpeechSynthesisUtterance);
        const puedeEscuchar = Boolean(Reconocimiento && window.isSecureContext);
        let reconocimiento = null, escuchando = false, reproduccion = null, temporizador = null;
        let voces = [], botones = new Set();
        const abierto = () => raiz.dataset.lumiAbierto === 'true';
        const informar = texto => { aviso.textContent = texto; aviso.hidden = !texto; };
        const etiquetaMicro = texto => {
            microfono.setAttribute('aria-label', texto);
            microfono.title = texto;
        };

        // abort() es asíncrono en Chrome. No ceder el micrófono hasta recibir onend.
        let propietario = null, pendiente = null, abortando = false;
        const arrancarPendiente = () => {
            if (propietario || !pendiente) return;
            const solicitud = pendiente;
            pendiente = null;
            propietario = solicitud.rec;
            abortando = false;
            try { solicitud.rec.start(); } catch (_) {
                propietario = null;
                solicitud.fallo();
                arrancarPendiente();
            }
        };
        const abortarReconocimiento = rec => {
            if (pendiente?.rec === rec) pendiente = null;
            if (propietario !== rec || abortando) return;
            abortando = true;
            try { rec.abort(); } catch (_) {
                // Si el navegador no confirma el cierre, no iniciar otra escucha.
                informar('No se pudo liberar el micrófono. Recarga la página o continúa escribiendo.');
            }
        };
        const iniciarReconocimiento = (rec, fallo) => {
            const alTerminar = rec.onend;
            rec.onend = () => {
                if (propietario === rec) { propietario = null; abortando = false; }
                alTerminar?.();
                arrancarPendiente();
            };
            pendiente = {rec, fallo};
            if (propietario) abortarReconocimiento(propietario);
            arrancarPendiente();
        };

        const sincronizar = () => {
            const activa = reconocimiento !== null;
            microfono.disabled = !puedeEscuchar || !disponible() || reproduccion !== null;
            microfono.setAttribute('aria-pressed', String(activa));
            etiquetaMicro(activa ? 'Terminar dictado' : 'Dictar consulta en español');
            entrada.readOnly = activa;
            // No enviar un borrador mientras el navegador aún lo está transcribiendo.
            enviar.disabled = !disponible() || activa;
            detenerAudio.disabled = reproduccion === null;
            detenerAudio.hidden = reproduccion === null;
            raiz.dataset.lumiDictado = String(escuchando);
            for (const boton of botones) {
                boton.disabled = !puedeHablar || activa;
                boton.setAttribute('aria-pressed', String(reproduccion?.boton === boton));
            }
            raiz.dataset.lumiEstado = !abierto() ? 'silencio'
                : escuchando ? 'escuchando' : reproduccion ? 'hablando' : estadoBase();
            // Restablece la detección en otras páginas si el usuario ya la habilitó.
            iniciarDespertar();
            etiquetaDespertar();
        };

        const detenerReproduccion = () => {
            if (!reproduccion) return;
            reproduccion = null; // Ignorar eventos tardíos de una reproducción cancelada.
            sintesis.cancel();
            informar('Reproducción detenida. Puedes escribir o dictar otra consulta.');
            sincronizar();
        };

        const detener = () => {
            const actual = reconocimiento;
            reconocimiento = null;
            escuchando = false;
            clearTimeout(temporizador);
            if (actual) abortarReconocimiento(actual);
            detenerReproduccion();
            if (actual) informar('Escucha detenida. Revisa el texto antes de enviarlo.');
            sincronizar();
        };

        const femenina = voz => /female|femenin|mujer|paulina|sabina|helena|elvira|dalia|laura|catalina|paloma|monica|mónica|luciana|mia|camila|sofia|sofía|conchita|penelope|penélope/i.test(voz.name);
        const puntaje = voz => {
            const lang = voz.lang.toLowerCase().replace('_', '-');
            return (lang === 'es-bo' ? 600 : /^es-(mx|ar|co|cl|pe|ec|uy|ve|do|gt|cr|cu|py|sv|hn|ni|pr|pa|us|419)$/.test(lang) ? 400 : /^es(?:-|$)/.test(lang) ? 200 : 0)
                + (femenina(voz) ? 80 : 0) + (voz.localService ? 10 : 0);
        };

        const cargarVoces = () => {
            if (!puedeHablar) { selector.disabled = true; return; }
            const anterior = selector.value;
            voces = [...sintesis.getVoices()].sort((a, b) => puntaje(b) - puntaje(a));
            const espanolas = voces.filter(v => /^es(?:[-_]|$)/i.test(v.lang));
            // La API no declara género: preferencia por nombres conocidos, no garantía.
            const preferida = espanolas.find(femenina) || espanolas[0] || voces.find(v => v.default) || voces[0];
            selector.replaceChildren();
            const automatica = document.createElement('option');
            automatica.value = '';
            automatica.textContent = preferida ? `Automática: ${preferida.name} (${preferida.lang})` : 'Voz del navegador (español)';
            selector.append(automatica);
            voces.forEach((voz, i) => {
                const opcion = document.createElement('option');
                opcion.value = voz.voiceURI || `${voz.name}:${voz.lang}:${i}`;
                opcion.textContent = `${voz.name} · ${voz.lang}${voz.localService ? ' · local' : ''}`;
                selector.append(opcion);
            });
            if ([...selector.options].some(o => o.value === anterior)) selector.value = anterior;
            selector.disabled = false;
            return preferida;
        };

        const reproducir = (texto, boton) => {
            if (!puedeHablar || !abierto() || reconocimiento) return;
            if (reproduccion?.boton === boton) { detenerReproduccion(); return; }
            detenerReproduccion();
            const preferida = cargarVoces();
            const elegida = voces.find((voz, i) => (voz.voiceURI || `${voz.name}:${voz.lang}:${i}`) === selector.value) || preferida;
            const alternativa = elegida && !/^es(?:[-_]|$)/i.test(elegida.lang);
            const frase = new window.SpeechSynthesisUtterance(texto);
            frase.lang = elegida?.lang || 'es-BO';
            if (elegida) frase.voice = elegida;
            frase.rate = 1;
            frase.pitch = 1;
            const actual = {frase, boton};
            reproduccion = actual; // Bloquea el micrófono y suspende la detección mientras suena la voz.
            pausarDespertar();
            frase.onstart = () => {
                if (reproduccion !== actual || !abierto()) return;
                informar('Lumi está hablando. El micrófono permanece desactivado.'
                    + (alternativa ? ' No hay una voz española seleccionada; puedes elegir otra voz.' : ''));
                sincronizar();
            };
            frase.onend = () => {
                if (reproduccion !== actual) return;
                reproduccion = null;
                informar('Reproducción terminada. Puedes escribir o dictar.');
                sincronizar();
            };
            frase.onerror = () => {
                if (reproduccion !== actual) return;
                reproduccion = null;
                informar('No se pudo reproducir el audio. Prueba otra voz; la respuesta escrita sigue disponible.');
                sincronizar();
            };
            informar('Preparando la lectura de la respuesta…');
            sincronizar();
            try { sintesis.speak(frase); } catch (_) { frase.onerror(); }
        };

        const agregarLectura = (mensaje, texto) => {
            if (!puedeHablar) return;
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'lumi-leer';
            boton.setAttribute('aria-label', 'Reproducir o detener esta respuesta en voz alta');
            boton.title = 'Escuchar respuesta';
            boton.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4zM16 8a6 6 0 0 1 0 8M19 5a10 10 0 0 1 0 14"/></svg>';
            boton.addEventListener('click', () => reproducir(texto, boton));
            botones.add(boton);
            mensaje.append(boton);
            sincronizar();
        };

        /* Detector existente: el consentimiento se pide una vez, no mediante un
         * interruptor permanente. Comparte el acceso exclusivo con el dictado. */
        const claveActivacion = 'cvs-lumi-activacion:' + new URL('.', location.href).pathname;
        const puedeDespertar = () => puedeEscuchar && Boolean(window.crypto?.subtle);
        let prefDespertar = false, despierto = null, despertarBloqueada = false;
        let sesionActivacion = '', preferenciaGuardada = '', decisionVoz = '', revisionSesion = 0;
        let saliendo = false, reinicioDespertar = null;
        if (puedeDespertar()) { try { preferenciaGuardada = sessionStorage.getItem(claveActivacion) || ''; } catch (_) { /* Sin persistencia. */ } }
        const fraseActivacion = texto => /\b(?:hola|oye)\b[,¡!.¿?\s]+lumi\b/i
            .test(String(texto).normalize('NFD').replace(/\p{Diacritic}/gu, ''));
        const guardarPreferencia = () => {
            preferenciaGuardada = JSON.stringify({sesion: sesionActivacion, decision: decisionVoz, requiereInteraccion: despertarBloqueada});
            try { sessionStorage.setItem(claveActivacion, preferenciaGuardada); } catch (_) { /* Solo se recuerda en esta página. */ }
        };
        const etiquetaDespertar = () => {
            const estado = !prefDespertar ? '' : despertarBloqueada ? 'requiere'
                : despierto ? (despierto.lumiIniciado ? 'escucha' : 'iniciando') : 'pausa';
            raiz.dataset.lumiDespertar = estado;
            const etiqueta = ({escucha: 'Micrófono activo: escuchando Hola, Lumi',
                iniciando: 'Solicitando micrófono', requiere: 'Llamada por voz detenida; puedes reactivarla',
                pausa: 'Llamada por voz en pausa'})[estado];
            const esfera = document.getElementById('lumi-botón');
            esfera.title = etiqueta || 'Abrir la asistente inteligente';
            esfera.setAttribute('aria-label', etiqueta ? `Abrir Lumi. ${etiqueta}` : 'Abrir la asistente inteligente');
            const habilitable = puedeDespertar() && Boolean(sesionActivacion) && activable() && !saliendo;
            consentimiento.hidden = !habilitable || !abierto() || decisionVoz !== '';
            autorizar.disabled = !habilitable;
            permitir.disabled = !habilitable;
            permitir.hidden = prefDespertar;
            desactivar.hidden = !prefDespertar;
            reintentar.hidden = !prefDespertar || !despertarBloqueada;
            reintentar.disabled = !habilitable;
            reactivar.hidden = !prefDespertar || !despertarBloqueada || abierto() || document.hidden || saliendo;
            reactivar.disabled = !habilitable;
            preferencia.textContent = !puedeDespertar() ? 'Llamada por voz no disponible en este navegador.'
                : despertarBloqueada ? 'El navegador detuvo el micrófono. Puedes reactivarlo o desactivar la llamada por voz.'
                    : prefDespertar ? 'Llamada por voz autorizada para esta sesión.'
                        : decisionVoz === 'desactivada' ? 'Llamada por voz desactivada.' : 'Llamada por voz sin autorizar.';
        };
        const desarmarDespertar = () => {
            clearTimeout(reinicioDespertar);
            reinicioDespertar = null;
            if (!despierto) return;
            const rec = despierto; despierto = null;
            abortarReconocimiento(rec);
        };
        const bloquearDespertar = texto => {
            despertarBloqueada = true;
            guardarPreferencia(); // No insistir al navegar si Chrome exige otro gesto o denegó el permiso.
            desarmarDespertar();
            informar(texto);
            etiquetaDespertar();
        };
        const iniciarDespertar = (gestoInicial = false) => {
            if (!puedeDespertar() || !prefDespertar || despierto || reinicioDespertar || despertarBloqueada
                || !sesionActivacion || !activable() || document.hidden || saliendo || reconocimiento || reproduccion || (abierto() && !gestoInicial)) return;
            let rec;
            try { rec = new Reconocimiento(); } catch (_) {
                bloquearDespertar('No se pudo iniciar el micrófono. Puedes reactivarlo o seguir escribiendo.'); return;
            }
            despierto = rec;
            rec.lang = 'es-BO';
            rec.continuous = true;
            rec.interimResults = true;
            rec.maxAlternatives = 1;
            rec.onstart = () => {
                if (despierto !== rec) return;
                rec.lumiIniciado = true;
                // El clic inicial solicita el permiso aun con el panel abierto; después se pausa.
                if (abierto() || document.hidden || saliendo) desarmarDespertar();
                etiquetaDespertar();
            };
            rec.onresult = evento => {
                if (despierto !== rec || document.hidden || saliendo || abierto() || reconocimiento || reproduccion) return;
                const ultimo = evento.results[evento.results.length - 1]?.[0]?.transcript;
                if (typeof ultimo !== 'string' || !fraseActivacion(ultimo)) return;
                desarmarDespertar();
                abrirPanel?.();
                informar(''); // La frase abre el historial: no se conserva ni se envía al chat.
            };
            rec.onerror = evento => {
                if (despierto !== rec || evento.error === 'no-speech') return;
                bloquearDespertar(evento.error === 'not-allowed' || evento.error === 'service-not-allowed'
                    ? 'Chrome detuvo la escucha. Revisa el permiso del micrófono y pulsa Reactivar llamada por voz.'
                    : 'La llamada por voz se detuvo. Puedes reactivarla o continuar escribiendo.');
            };
            rec.onend = () => {
                if (despierto !== rec) return;
                despierto = null;
                if (prefDespertar && !despertarBloqueada && !abierto() && !document.hidden && !saliendo) {
                    reinicioDespertar = setTimeout(() => { reinicioDespertar = null; iniciarDespertar(); }, 750);
                }
                etiquetaDespertar();
            };
            iniciarReconocimiento(rec, () => {
                if (despierto !== rec) return;
                despierto = null;
                bloquearDespertar('Chrome no pudo iniciar la escucha. Pulsa Reactivar llamada por voz o revisa el permiso del micrófono.');
            });
            etiquetaDespertar();
        };
        const pausarDespertar = () => { desarmarDespertar(); etiquetaDespertar(); };
        const autorizarDespertar = () => {
            if (!puedeDespertar() || !sesionActivacion || !activable() || document.hidden || saliendo) return;
            prefDespertar = true;
            decisionVoz = 'permitida';
            despertarBloqueada = false;
            guardarPreferencia();
            informar('');
            iniciarDespertar(true); // Siempre desde el botón de autorización o reactivación, nunca al ofrecerlo.
            etiquetaDespertar();
            if (abierto()) entrada.focus();
        };
        const desactivarDespertar = () => {
            prefDespertar = false;
            decisionVoz = 'desactivada';
            despertarBloqueada = false;
            guardarPreferencia(); // «Ahora no» y desactivar también se recuerdan: sin ofertas repetitivas.
            desarmarDespertar();
            informar('');
            etiquetaDespertar();
            if (abierto()) entrada.focus();
        };
        for (const boton of [autorizar, permitir, reintentar, reactivar]) boton.addEventListener('click', autorizarDespertar);
        for (const boton of [desactivar, posponer]) boton.addEventListener('click', desactivarDespertar);
        const vigilarAbierto = new MutationObserver(() => {
            if (abierto()) pausarDespertar();
            else iniciarDespertar();
            etiquetaDespertar();
        });
        vigilarAbierto.observe(raiz, {attributes: true, attributeFilter: ['data-lumi-abierto']});
        const suspenderDespertar = () => {
            revisionSesion++;
            prefDespertar = false;
            sesionActivacion = '';
            pausarDespertar(); // Conserva la decisión solo hasta revalidar la misma sesión.
        };
        const detenerDespertar = () => {
            suspenderDespertar();
            preferenciaGuardada = '';
            decisionVoz = '';
            try { sessionStorage.removeItem(claveActivacion); } catch (_) { /* Sesión sin almacenamiento. */ }
            despertarBloqueada = false;
            etiquetaDespertar();
        };
        const validarSesion = async token => {
            if (!puedeDespertar() || typeof token !== 'string' || !token) { detenerDespertar(); return; }
            const revision = revisionSesion;
            try {
                const huella = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(token));
                if (revision !== revisionSesion || saliendo) return;
                const sesion = Array.from(new Uint8Array(huella), b => b.toString(16).padStart(2, '0')).join('');
                let guardada = null;
                try {
                    // Conservar autorizaciones explícitas de la versión anterior, vinculadas a esta sesión.
                    guardada = /^[a-f0-9]{64}$/.test(preferenciaGuardada)
                        ? {sesion: preferenciaGuardada, decision: 'permitida'} : JSON.parse(preferenciaGuardada);
                } catch (_) { /* Todavía sin decisión. */ }
                if ((sesionActivacion && sesionActivacion !== sesion) || (guardada && guardada.sesion !== sesion)) {
                    detenerDespertar(); guardada = null;
                }
                sesionActivacion = sesion;
                decisionVoz = ['permitida', 'desactivada'].includes(guardada?.decision) ? guardada.decision : '';
                prefDespertar = decisionVoz === 'permitida';
                despertarBloqueada = prefDespertar && guardada?.requiereInteraccion === true;
            } catch (_) { detenerDespertar(); }
            etiquetaDespertar();
        };

        microfono.addEventListener('click', () => {
            if (!puedeEscuchar || !disponible() || reproduccion || !abierto() || document.hidden || saliendo) return;
            if (reconocimiento) {
                informar('Terminando de escuchar…');
                try { reconocimiento.stop(); } catch (_) { detener(); }
                return;
            }
            pausarDespertar(); // El detector de activación cede el micrófono al dictado.
            let actual;
            try { actual = new Reconocimiento(); } catch (_) {
                informar('No se pudo iniciar el reconocimiento de voz. Puedes seguir escribiendo.'); return;
            }
            reconocimiento = actual;
            const borrador = entrada.value.trim();
            let error = '', recibioTexto = false, recortado = false;
            actual.lang = 'es-BO';
            actual.continuous = false;
            actual.interimResults = true;
            actual.maxAlternatives = 1;
            actual.onstart = () => {
                if (reconocimiento !== actual || !abierto()) { try { actual.abort(); } catch (_) {} return; }
                escuchando = true;
                informar('Escuchando en español… Pulsa el micrófono para terminar.');
                sincronizar();
            };
            actual.onresult = evento => {
                if (reconocimiento !== actual || !abierto() || reproduccion) return;
                const partes = [];
                for (let i = 0; i < evento.results.length; i++) {
                    const transcripcion = evento.results[i][0]?.transcript;
                    if (typeof transcripcion === 'string') partes.push(transcripcion);
                }
                const texto = [borrador, partes.join(' ').trim()].filter(Boolean).join(' ');
                const limite = entrada.maxLength > 0 ? entrada.maxLength : 700;
                recortado = texto.length > limite;
                entrada.value = texto.slice(0, limite);
                recibioTexto = partes.some(parte => parte.trim() !== '');
                entrada.dispatchEvent(new Event('input', {bubbles: true}));
            };
            actual.onerror = evento => {
                if (reconocimiento !== actual) return;
                error = ({
                    'not-allowed': 'Permiso de micrófono denegado. Habilítalo en los permisos del sitio de Chrome o continúa escribiendo.',
                    'service-not-allowed': 'El servicio de reconocimiento no está permitido. Puedes continuar escribiendo.',
                    'no-speech': 'No se detectó voz. Intenta otra vez o escribe tu consulta.',
                    'audio-capture': 'No se encontró un micrófono disponible. Revisa el dispositivo o escribe tu consulta.',
                    'network': 'El servicio de reconocimiento no pudo conectarse. Puede requerir Internet; el chat escrito sigue disponible.',
                    'language-not-supported': 'El reconocimiento no admite este idioma en tu navegador. Puedes continuar escribiendo.',
                    'aborted': 'Escucha cancelada. Puedes revisar el texto o volver a dictar.',
                })[evento.error] || 'No se pudo reconocer la voz. Puedes continuar escribiendo.';
                informar(error);
                // El error termina esta sesión; invalidar callbacks y liberar controles.
                detener();
                informar(error);
            };
            actual.onend = () => {
                if (reconocimiento !== actual) return;
                reconocimiento = null;
                escuchando = false;
                clearTimeout(temporizador);
                informar(error || (recortado ? 'Dictado recortado al límite del chat. Revísalo antes de enviar.'
                    : recibioTexto ? 'Dictado listo. Revisa el texto y pulsa Enviar cuando quieras.' : 'Escucha terminada sin texto nuevo. Puedes volver a intentar o escribir.'));
                sincronizar();
                if (abierto()) entrada.focus();
            };
            informar('Solicitando micrófono… El reconocimiento puede utilizar servicios externos. Revisa Privacidad y datos.');
            sincronizar();
            iniciarReconocimiento(actual, () => {
                detener();
                informar('No se pudo iniciar el micrófono. Comprueba el permiso y continúa escribiendo si lo necesitas.');
            });
            if (reconocimiento === actual) temporizador = setTimeout(() => {
                if (reconocimiento === actual) { try { actual.stop(); } catch (_) { detener(); } }
            }, 30000);
        });

        detenerAudio.addEventListener('click', detenerReproduccion);
        selector.addEventListener('change', detenerReproduccion);
        window.addEventListener('pagehide', () => { saliendo = true; pausarDespertar(); detener(); });
        window.addEventListener('pageshow', () => { saliendo = false; });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) { pausarDespertar(); detener(); }
            // Al volver, lumi.js revalida primero la sesión y llama a sincronizar.
        });
        if (puedeHablar) sintesis.addEventListener('voiceschanged', cargarVoces);
        informar(!puedeEscuchar ? 'Reconocimiento de voz no disponible en este navegador o contexto. Usa Chrome en localhost/HTTPS; puedes seguir escribiendo.'
            : !puedeHablar ? 'Lectura en voz alta no disponible. Puedes dictar y usar el chat escrito.' : '');
        cargarVoces();
        sincronizar();
        etiquetaDespertar();
        return {sincronizar, detener, agregarLectura, escuchando: () => reconocimiento !== null,
            detenerDespertar, suspenderDespertar, validarSesion,
            limpiarLecturas: () => { detenerReproduccion(); botones.clear(); }};
    };
})();
