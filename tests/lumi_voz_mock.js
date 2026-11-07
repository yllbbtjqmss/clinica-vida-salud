/* Exclusivo de Playwright: ningún micrófono ni motor de síntesis real se utiliza. */
(() => {
    const prueba = window.lumiVozPrueba = {
        inicios: 0, intentos: 0, paradas: 0, abortos: 0, cancelaciones: 0, frases: [], actual: null,
        lanzarErrorInicio: false,
        exigirGesto: sessionStorage.getItem('lumi-prueba-exigir-gesto') === 'si',
        retrasarFin: false, activos: new Set(), simultaneos: 0,
        voces: [],
    };
    class ReconocimientoFicticio {
        start() {
            prueba.intentos++;
            if (prueba.lanzarErrorInicio || (prueba.exigirGesto && !navigator.userActivation.isActive)) throw new Error('Interacción requerida (simulación)');
            prueba.inicios++;
            prueba.actual = this;
            prueba.activos.add(this);
            prueba.simultaneos = Math.max(prueba.simultaneos, prueba.activos.size);
            queueMicrotask(() => { if (prueba.activos.has(this)) this.iniciar(); });
        }
        finalizar() { prueba.activos.delete(this); this.onend?.(); }
        stop() { prueba.paradas++; if (!prueba.retrasarFin) this.finalizar(); }
        abort() { prueba.abortos++; if (!prueba.retrasarFin) this.finalizar(); }
        iniciar() { this.onstart?.(); }
        resultado(texto, final = true) {
            const resultado = [{transcript: texto}]; resultado.isFinal = final;
            this.onresult?.({results: [resultado], resultIndex: 0});
        }
        error(codigo) { this.onerror?.({error: codigo}); }
    }
    class SintesisFicticia extends EventTarget {
        getVoices() { return prueba.voces; }
        speak(frase) { prueba.frases.push(frase); this.actual = frase; frase.onstart?.(); }
        cancel() { prueba.cancelaciones++; const actual = this.actual; this.actual = null; actual?.onend?.(); }
        terminar() { this.actual?.onend?.(); this.actual = null; }
        error() { this.actual?.onerror?.({error: 'synthesis-failed'}); this.actual = null; }
    }
    const sintesis = new SintesisFicticia();
    Object.defineProperty(window, 'SpeechRecognition', {value: ReconocimientoFicticio, configurable: true});
    Object.defineProperty(window, 'webkitSpeechRecognition', {value: undefined, configurable: true});
    Object.defineProperty(window, 'speechSynthesis', {value: sintesis, configurable: true});
    Object.defineProperty(window, 'SpeechSynthesisUtterance', {value: class { constructor(text) { this.text = text; } }, configurable: true});
    prueba.cargarVoces = (voces = [
        {name: 'Voz predeterminada', lang: 'en-US', voiceURI: 'en', localService: true, default: true},
        {name: 'Elvira', lang: 'es-ES', voiceURI: 'es', localService: true},
        {name: 'Sabina', lang: 'es-MX', voiceURI: 'mx', localService: true},
        {name: 'Camila', lang: 'es-BO', voiceURI: 'bo', localService: true},
    ]) => { prueba.voces = voces; sintesis.dispatchEvent(new Event('voiceschanged')); };
})();
