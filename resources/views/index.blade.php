<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>C.O.R.E. Assistant</title>
    
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#00ffcc">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #050505; color: #00ffcc; font-family: 'Courier New', monospace; overflow: hidden; }
        .radar { position: relative; }
        .radar::before, .radar::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            border-radius: 50%; border: 2px solid #00ffcc; opacity: 0; z-index: -1;
        }
        .escuchando::before { animation: pulsoRadar 2s linear infinite; }
        .escuchando::after { animation: pulsoRadar 2s linear infinite 1s; }
        @keyframes pulsoRadar { 0% { transform: scale(1); opacity: 0.8; } 100% { transform: scale(2); opacity: 0; } }
        #chat-box::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="h-screen w-screen flex flex-col items-center justify-between p-4 relative">

    <!-- Botón de Mute Esquina Superior Derecha -->
    <button id="btn-mute" class="absolute top-6 right-6 text-gray-500 hover:text-[#00ffcc] transition-colors focus:outline-none">
        <!-- Icono Volumen Encendido (Por defecto) -->
        <svg id="icon-vol-on" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M11 5L6 9H2v6h4l5 4V5z"></path>
        </svg>
        <!-- Icono Volumen Apagado (Oculto) -->
        <svg id="icon-vol-off" class="w-7 h-7 hidden text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h2.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"></path>
        </svg>
    </button>

    <!-- Header -->
    <header class="w-full text-center mt-8">
        <h1 class="text-4xl font-bold tracking-widest text-white shadow-neon">C.O.R.E.</h1>
        <p class="text-xs text-gray-500 uppercase tracking-widest mt-1" id="status-text">Sistema en Espera</p>
    </header>

    <!-- Chat Logs -->
    <div id="chat-box" class="w-full max-w-lg flex-1 overflow-y-auto p-4 flex flex-col space-y-4 mb-4">
        <div class="self-start text-xs text-gray-400">Iniciando en código base impordiesel... OK.</div>
    </div>

    <!-- Controles (Micrófono, Input y Enviar) -->
    <div class="mb-6 w-full max-w-lg flex items-center gap-3 px-2">
        <button id="btn-mic" class="radar w-14 h-14 shrink-0 rounded-full bg-transparent border-2 border-[#00ffcc] text-[#00ffcc] flex justify-center items-center focus:outline-none transition-colors duration-300">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
        </button>

        <input type="text" id="input-texto" placeholder="Escriba un comando..." 
               class="flex-1 h-12 bg-black border border-gray-700 rounded-full px-4 text-[#00ffcc] placeholder-gray-600 focus:outline-none focus:border-[#00ffcc] transition-colors">

        <button id="btn-enviar" class="w-12 h-12 shrink-0 rounded-full bg-[#00ffcc] text-black flex justify-center items-center focus:outline-none hover:bg-white transition-colors">
            <svg class="w-5 h-5 ml-1" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"></path></svg>
        </button>
    </div>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js');
        }

        const btnMic = document.getElementById('btn-mic');
        const btnEnviar = document.getElementById('btn-enviar');
        const inputTexto = document.getElementById('input-texto');
        const chatBox = document.getElementById('chat-box');
        const statusText = document.getElementById('status-text');
        
        // Elementos del Mute
        const btnMute = document.getElementById('btn-mute');
        const iconVolOn = document.getElementById('icon-vol-on');
        const iconVolOff = document.getElementById('icon-vol-off');
        let isMuted = false;

        // --- LÓGICA DE MUTE ---
        btnMute.addEventListener('click', () => {
            isMuted = !isMuted;
            if (isMuted) {
                iconVolOn.classList.add('hidden');
                iconVolOff.classList.remove('hidden');
                // Si la IA estaba hablando justo en este momento, la callamos de golpe
                window.speechSynthesis.cancel(); 
            } else {
                iconVolOn.classList.remove('hidden');
                iconVolOff.classList.add('hidden');
            }
        });
        
        // --- 1. LÓGICA DEL MICRÓFONO ---
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        let reconocimiento = null;

        if (SpeechRecognition) {
            reconocimiento = new SpeechRecognition();
            reconocimiento.lang = 'es-ES';
            reconocimiento.interimResults = false;

            reconocimiento.onstart = () => {
                // Si la IA estaba hablando, la callamos para poder escuchar al jefe
                window.speechSynthesis.cancel(); 
                btnMic.classList.add('escuchando', 'bg-[#00ffcc]', 'text-black');
                statusText.innerText = 'ESCUCHANDO...';
                statusText.classList.add('text-[#00ffcc]');
            };

            reconocimiento.onresult = (evento) => {
                const texto = evento.results[0][0].transcript;
                apagarMicrofono();
                procesarEntrada(texto);
            };

            reconocimiento.onerror = () => {
                apagarMicrofono();
                statusText.innerText = 'ERROR DE AUDIO';
                statusText.classList.add('text-red-500');
            };

            reconocimiento.onend = () => apagarMicrofono();
        }

        btnMic.addEventListener('click', () => {
            if (reconocimiento) reconocimiento.start();
        });

        function apagarMicrofono() {
            btnMic.classList.remove('escuchando', 'bg-[#00ffcc]', 'text-black');
            statusText.innerText = 'PROCESANDO...';
            statusText.classList.remove('text-[#00ffcc]');
        }

        // --- 2. LÓGICA DEL TEXTO ---
        btnEnviar.addEventListener('click', () => {
            if(inputTexto.value.trim() !== '') {
                procesarEntrada(inputTexto.value);
                inputTexto.value = ''; 
            }
        });

        inputTexto.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && inputTexto.value.trim() !== '') {
                procesarEntrada(inputTexto.value);
                inputTexto.value = ''; 
            }
        });

        // --- 3. LÓGICA CENTRAL ---
        function procesarEntrada(texto) {
            agregarMensaje('Jefe', texto);
            enviarAOrquestador(texto);
        }

        function agregarMensaje(emisor, texto) {
            const color = emisor === 'Jefe' ? 'text-white' : 'text-[#00ffcc]';
            const align = emisor === 'Jefe' ? 'self-end text-right' : 'self-start text-left';
            chatBox.innerHTML += `<div class="${align} max-w-[80%]"><span class="text-[10px] uppercase text-gray-500 block mb-1">${emisor}</span><p class="${color} text-sm">${texto}</p></div>`;
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        async function enviarAOrquestador(texto) {
            statusText.innerText = 'PROCESANDO...';
            
            try {
                const response = await fetch("/api/hablar", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ mensaje: texto })
                });

                const data = await response.json();
                
                statusText.innerText = 'SISTEMA EN ESPERA';
                agregarMensaje('C.O.R.E.', data.respuesta);
                
                hablar(data.respuesta);

            } catch (error) {
                statusText.innerText = 'ERROR DE RED';
                agregarMensaje('C.O.R.E.', 'Fallo de conexión con el núcleo cognitivo.');
            }
        }

        function hablar(texto) {
            if (isMuted) return; // Si está en silencio, abortamos aquí y no suena nada
            
            const sintesis = window.speechSynthesis;
            const msj = new SpeechSynthesisUtterance(texto);
            msj.lang = 'es-ES';
            msj.rate = 1.1; 
            sintesis.speak(msj);
        }
    </script>
</body>
</html>