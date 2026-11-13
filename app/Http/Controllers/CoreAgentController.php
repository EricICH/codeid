<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoreAgentController extends Controller
{
    public function procesarComando(Request $request)
    {
        $texto = $request->input('mensaje');
        $historial = $request->input('historial', []);

        if (!$texto) {
            return response()->json(['respuesta' => 'No logré escuchar el comando, señor.']);
        }

        Log::info('--- NUEVO COMANDO C.O.R.E: ' . $texto . ' ---');

        // =====================================================================
        // 1. DESCARGA DEL CEREBRO Y HERRAMIENTAS (DINÁMICO)
        // =====================================================================
        $mainSystemUrl = env('MAIN_SYSTEM_URL', 'http://localhost');
        $urlConfig = $mainSystemUrl . '/api/ia/configuracion';

        try {
            $configuracion = Http::timeout(10)->get($urlConfig);
            if (!$configuracion->successful()) {
                Log::error("Fallo al conectar con el Cerebro Central. Status: " . $configuracion->status());
                return response()->json(['respuesta' => 'Señor, no logro establecer enlace con los servidores centrales de configuración.']);
            }
            $dataConfig = $configuracion->json();
        } catch (\Exception $e) {
            Log::error("Error de Red al Cerebro: " . $e->getMessage());
            return response()->json(['respuesta' => 'Señor, los servidores de Impordiesel están fuera de alcance.']);
        }

        $settings = $dataConfig['settings'] ?? [];
        $herramientasDB = $dataConfig['tools'] ?? [];

        // Extraemos variables dinámicas (Si no existen, usamos valores por defecto)
        $systemInstruction = $settings['system_prompt'] ?? 'Eres un asistente corporativo. Responde brevemente.';
        $model = $settings['model_name'] ?? 'openai/gpt-oss-20b';
        $temperature = isset($settings['temperature']) ? (float)$settings['temperature'] : 0.2;
        $urlLLM = $settings['api_base_url'] ?? 'https://api.groq.com/openai/v1/chat/completions';
        
        $apiKey = env('OPENAI_API_KEY'); // Mantenemos la llave en el .env por seguridad

        // =====================================================================
        // 2. FORMATEO DE LAS HERRAMIENTAS PARA LA IA
        // =====================================================================
        $tools = [];
        $rutasHerramientas = []; // Diccionario para saber dónde llamar luego

        foreach ($herramientasDB as $tool) {
            $tools[] = [
                "type" => "function",
                "function" => [
                    "name" => $tool['name'],
                    "description" => $tool['description'],
                    // Forzamos que sea un objeto si viene vacío
                    "parameters" => empty($tool['parameters_schema']) ? new \stdClass() : $tool['parameters_schema']
                ]
            ];

            // Guardamos el endpoint y el método para usarlo en la Fase 2
            $rutasHerramientas[$tool['name']] = [
                'url' => $tool['api_endpoint'],
                'method' => strtoupper($tool['http_method'])
            ];
        }

        // =====================================================================
        // 3. ARMADO DEL CONTEXTO Y LA MEMORIA
        // =====================================================================
        $messages = [
            ["role" => "system", "content" => $systemInstruction]
        ];

        // Inyectar memoria
        $historialFiltrado = array_slice($historial, 0, -1);
        foreach ($historialFiltrado as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = ["role" => $msg['role'], "content" => $msg['content']];
            }
        }
        $messages[] = ["role" => "user", "content" => $texto];

        $payload = [
            "model" => $model,
            "messages" => $messages,
            "temperature" => $temperature
        ];

        // Solo inyectamos "tools" si hay herramientas activas en la BD
        if (count($tools) > 0) {
            $payload["tools"] = $tools;
            $payload["tool_choice"] = "auto";
        }

        // =====================================================================
        // 4. FASE 1: LLAMADA A GROQ
        // =====================================================================
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($urlLLM, $payload);

            if (!$response->successful()) {
                Log::error('Error Groq Fase 1: ' . $response->body());
                return response()->json(['respuesta' => 'Fallo en la primera fase del núcleo cognitivo.']);
            }

            $mensajeIA = $response->json('choices.0.message');

            // =====================================================================
            // 5. EJECUCIÓN DINÁMICA DE LA HERRAMIENTA QUE ELIGIÓ LA IA
            // =====================================================================
            if (isset($mensajeIA['tool_calls']) && count($mensajeIA['tool_calls']) > 0) {
                
                $toolCall = $mensajeIA['tool_calls'][0];
                $nombreHerramienta = $toolCall['function']['name'];
                $argumentosHerramienta = json_decode($toolCall['function']['arguments'], true) ?? [];

                Log::info("La IA decidió usar la herramienta: " . $nombreHerramienta);

                if (isset($rutasHerramientas[$nombreHerramienta])) {
                    $urlEjecutar = $rutasHerramientas[$nombreHerramienta]['url'];
                    $metodoEjecutar = $rutasHerramientas[$nombreHerramienta]['method'];

                    Log::info("Consultando Endpoint: {$metodoEjecutar} {$urlEjecutar}");

                    // Ejecutar llamada dinámica (GET o POST) al sistema viejo
                    try {
                        if ($metodoEjecutar === 'POST') {
                            $resHerramienta = Http::timeout(15)->post($urlEjecutar, $argumentosHerramienta);
                        } else {
                            $resHerramienta = Http::timeout(15)->get($urlEjecutar, $argumentosHerramienta);
                        }

                        $contenidoHerramienta = $resHerramienta->successful() 
                            ? $resHerramienta->body() 
                            : "Error de la herramienta: " . $resHerramienta->status();

                    } catch (\Exception $e) {
                        $contenidoHerramienta = "Fallo de red conectando al endpoint de la herramienta: " . $e->getMessage();
                    }
                } else {
                    $contenidoHerramienta = "Error crítico: La IA intentó usar una herramienta que no está en la base de datos.";
                }

                // =====================================================================
                // 6. FASE 2: DEVOLVER DATOS A GROQ
                // =====================================================================
                $messages[] = $mensajeIA; 
                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCall['id'],
                    "content" => (string)$contenidoHerramienta
                ];

                $payloadFase2 = [
                    "model" => $model,
                    "messages" => $messages,
                    "temperature" => $temperature,
                    "tools" => $tools // Re-enviamos el catálogo
                ];

                $responseFase2 = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(30)->post($urlLLM, $payloadFase2);

                if ($responseFase2->successful()) {
                    $respuestaFinal = $responseFase2->json('choices.0.message.content');
                } else {
                    Log::error('Error Groq Fase 2: ' . $responseFase2->body());
                    $respuestaFinal = 'Error al procesar el análisis de los datos.';
                }

            } else {
                $respuestaFinal = $mensajeIA['content'] ?? 'Comando recibido sin accionar sistema.';
            }

            // =====================================================================
            // 7. RESPUESTA AL NAVEGADOR
            // =====================================================================
            if (empty($respuestaFinal)) {
                $respuestaFinal = "Señor, proceso completado, pero el núcleo omitió la respuesta verbal.";
            }

            $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaFinal);
            return response()->json([
                'respuesta' => trim($respuestaLimpia)
            ]);

        } catch (\Exception $e) {
            Log::error('Exception General Agente: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un fallo general en la red cognitiva.'
            ]);
        }
    }
}