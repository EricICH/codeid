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

        if (!$texto) {
            return response()->json(['respuesta' => 'No logré escuchar el comando, señor.']);
        }

        Log::info('--- NUEVO COMANDO C.O.R.E: ' . $texto . ' ---');

        $apiKey = env('OPENAI_API_KEY');
        
        $systemInstruction = "Eres C.O.R.E., el Asistente Corporativo de Impordiesel. Eres profesional, conciso y te diriges al usuario como 'Señor'. Si te preguntan por combustible o inventario, usa la herramienta. Cuando recibas los datos, NO leas todos los números. Da un resumen rápido del inventario físico total, el volumen comprometido y el combustible libre real para ventas. Tu respuesta será dictada, usa lenguaje natural.";

        $url = "https://api.groq.com/openai/v1/chat/completions";
        $model = "openai/gpt-oss-20b"; 

        $messages = [
            ["role" => "system", "content" => $systemInstruction],
            ["role" => "user", "content" => $texto]
        ];

        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "consultar_metricas_dashboard",
                    "description" => "Consulta las métricas gerenciales del combustible. Devuelve el inventario físico, comprometido y disponible.",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "ejecutar" => ["type" => "boolean", "description" => "Siempre true"]
                        ]
                    ]
                ]
            ]
        ];

        $payload = [
            "model" => $model,
            "messages" => $messages,
            "tools" => $tools,
            "tool_choice" => "auto",
            "temperature" => 0.2
        ];

        try {
            Log::info('Paso 1: Iniciando llamada a Groq');
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Error Groq Fase 1: ' . $response->body());
                return response()->json(['respuesta' => 'Fallo en la primera fase del núcleo cognitivo.']);
            }

            $mensajeIA = $response->json('choices.0.message');
            Log::info('Paso 2: Groq respondió Fase 1. ¿Usó herramienta? ' . (isset($mensajeIA['tool_calls']) ? 'SÍ' : 'NO'));

            if (isset($mensajeIA['tool_calls']) && count($mensajeIA['tool_calls']) > 0) {
                
                $toolCall = $mensajeIA['tool_calls'][0];
                
                // AQUÍ: Pon tu URL real. SI ESTÁS EN LOCAL, ESTO PUEDE CAUSAR DEADLOCK.
                $urlSistemaViejo = "https://tucombustible.com.ve/api/admin/reportes/combustible-dashboard"; 
                
                Log::info('Paso 3: Llamando a la API de TuCombustible: ' . $urlSistemaViejo);
                $respuestaInventario = Http::timeout(10)->get($urlSistemaViejo);
                
                if ($respuestaInventario->successful()) {
                    Log::info('Paso 4: Datos obtenidos con éxito de la API de TuCombustible.');
                    $datosJSON = $respuestaInventario->json();
                    
                    $resumenParaIA = [
                        'total_fisico_general' => $datosJSON['general_fisico'] ?? 0,
                        'total_comprometido_viajes' => $datosJSON['general_comprometido'] ?? 0,
                        'disponible_para_nueva_venta' => $datosJSON['general_venta'] ?? 0,
                    ];
                    $contenidoHerramienta = json_encode($resumenParaIA);
                } else {
                    Log::error('Error API Impordiesel: Status ' . $respuestaInventario->status());
                    $contenidoHerramienta = "Error: El sistema central devolvió un código " . $respuestaInventario->status();
                }

                $messages[] = $mensajeIA; 
                $messages[] = [
                    "role" => "tool",
                    "tool_call_id" => $toolCall['id'],
                    "content" => $contenidoHerramienta
                ];

                $payloadFase2 = [
                    "model" => $model,
                    "messages" => $messages,
                    "tools" => $tools, // Obligatorio reenviarlo
                    "temperature" => 0.2
                ];

                Log::info('Paso 5: Enviando datos de vuelta a Groq para Fase 2');
                $responseFase2 = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(30)->post($url, $payloadFase2);

                if ($responseFase2->successful()) {
                    $respuestaFinal = $responseFase2->json('choices.0.message.content');
                    Log::info('Paso 6: Éxito total. Respuesta generada.');
                } else {
                    Log::error('Error Groq Fase 2: ' . $responseFase2->body());
                    $respuestaFinal = 'Error al procesar las métricas financieras.';
                }

            } else {
                $respuestaFinal = $mensajeIA['content'] ?? 'Comando recibido, pero sin parámetros.';
            }

            // Si por alguna razón la IA devuelve null
            if (empty($respuestaFinal)) {
                $respuestaFinal = "Señor, he procesado los datos pero el núcleo cognitivo omitió la respuesta verbal.";
            }

            $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaFinal);
            Log::info('--- FIN COMANDO: Devolviendo respuesta al navegador ---');
            
            return response()->json([
                'respuesta' => trim($respuestaLimpia)
            ]);

        } catch (\Exception $e) {
            Log::error('Exception C.O.R.E Catch Principal: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un fallo de servidor.'
            ]);
        }
    }
}