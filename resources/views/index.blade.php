<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>C.O.D.E. Assistant</title>
    
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
<body class="h-screen w-screen flex flex-col items-center justify-between p-4">

    <header class="w-full text-center mt-8">
        <h1 class="text-4xl font-bold tracking-widest text-white shadow-neon">C.O.D.E.</h1>
        <p class="text-xs text-gray-500 uppercase tracking-widest mt-1" id="status-text">Sistema en Espera</p>
    </header>

    <div id="chat-box" class="w-full max-w-lg h-64 overflow-y-auto p-4 flex flex-col space-y-4 mb-4">
        <div class="self-start text-xs text-gray-400">Iniciando en código base impordiesel... OK.</div>
    </div>

    <div class="mb-12 w-full flex justify-center">
        <button id="btn-mic" class="radar w-24 h-24 rounded-full bg-transparent border-2 border-[#00ffcc] text-[#00ffcc] flex justify-center items-center focus:outline-none transition-colors duration-300">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>
        </button>
    </div>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js');
        }

        const btnMic = document.getElementById('btn-mic');
        const chatBox = document.getElementById('chat-box');
        const statusText = document.getElementById('status-text');
        
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        let reconocimiento = null;

        if (SpeechRecognition) {
            reconocimiento = new SpeechRecognition();
            reconocimiento.lang = 'es-ES';
            reconocimiento.interimResults = false;

            reconocimiento.onstart = () => {
                btnMic.classList.add('escuchando', 'bg-[#00ffcc]', 'text-black');
                statusText.innerText = 'ESCUCHANDO...';
                statusText.classList.add('text-[#00ffcc]');
            };

            reconocimiento.onresult = (evento) => {
                const texto = evento.results[0][0].transcript;
                apagarMicrofono();
                agregarMensaje('Jefe', texto);
                enviarAOrquestador(texto);
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

        function agregarMensaje(emisor, texto) {
            const color = emisor === 'Jefe' ? 'text-white' : 'text-[#00ffcc]';
            const align = emisor === 'Jefe' ? 'self-end text-right' : 'self-start text-left';
            chatBox.innerHTML += `<div class="${align} max-w-[80%]"><span class="text-[10px] uppercase text-gray-500 block mb-1">${emisor}</span><p class="${color} text-sm">${texto}</p></div>`;
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        async function enviarAOrquestador(texto) {
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
                agregarMensaje('C.O.D.E.', data.respuesta);
                hablar(data.respuesta);

            } catch (error) {
                statusText.innerText = 'ERROR DE RED';
                agregarMensaje('C.O.D.E.', 'Fallo de conexión.');
            }
        }

        function hablar(texto) {
            const sintesis = window.speechSynthesis;
            const msj = new SpeechSynthesisUtterance(texto);
            msj.lang = 'es-ES';
            msj.rate = 1.1; 
            sintesis.speak(msj);
        }
    </script>
</body>
</html>