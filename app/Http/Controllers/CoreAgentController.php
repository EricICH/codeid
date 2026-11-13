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

        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            return response()->json(['respuesta' => 'Error de sistema. Falta la credencial API universal.']);
        }

        // 1. INSTRUCCIÓN GERENCIAL (Ajustada para métricas financieras/logísticas)
        $systemInstruction = "Eres C.O.R.E., el Asistente Corporativo de Alta Gerencia de Impordiesel. Eres profesional, conciso y te diriges al usuario como 'Señor' o 'Director'. Responde en español, de forma ejecutiva. Si te preguntan por combustible, inventario, disponibilidad o capacidad de venta, USA OBLIGATORIAMENTE LA HERRAMIENTA DISPONIBLE. Cuando tengas los datos, no leas números crudos uno por uno. Da un resumen ejecutivo diferenciando entre: el inventario físico total, el volumen ya comprometido en viajes, y el combustible libre real para ventas nuevas. Tu respuesta será dictada por un sintetizador de voz, así que NO uses viñetas raras ni markdown.";

        $url = "https://api.groq.com/openai/v1/chat/completions";
        $model = "openai/gpt-oss-20b"; 

        $messages = [
            ["role" => "system", "content" => $systemInstruction],
            ["role" => "user", "content" => $texto]
        ];

        // 2. DEFINICIÓN DE LA HERRAMIENTA DEL DASHBOARD (A prueba de tontos y de PHP)
        $tools = [
            [
                "type" => "function",
                "function" => [
                    "name" => "consultar_metricas_dashboard",
                    "description" => "Consulta las métricas gerenciales del combustible. Devuelve el inventario físico en tanques, vehículos precargados, volumen comprometido en despachos programados y la disponibilidad real para la venta.",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "ejecutar" => [
                                "type" => "boolean",
                                "description" => "Parámetro de control interno. Siempre true."
                            ]
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
            // Llamada inicial
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Error API Inicial: ' . $response->body());
                return response()->json(['respuesta' => 'Fallo de conexión en la primera fase del núcleo cognitivo.']);
            }

            $responseData = $response->json();
            $mensajeIA = $responseData['choices'][0]['message'];

            // 3. LA IA DECIDE USAR LA HERRAMIENTA
            if (isset($mensajeIA['tool_calls']) && count($mensajeIA['tool_calls']) > 0) {
                
                $toolCall = $mensajeIA['tool_calls'][0];
                
                if ($toolCall['function']['name'] === 'consultar_metricas_dashboard') {
                    
                    // --- CONEXIÓN AL NUEVO ENDPOINT DEL DASHBOARD ---
                    // IMPORTANTE: Cambia "tu-sistema-viejo.com" por el dominio real.
                    // Y mantuve tu error ortográfico "comnbustible-dashboard" para que no se rompa la ruta.
                    $urlSistemaViejo = "https://tucombustible.com.ve/api/admin/reportes/comnbustible-dashboard"; 
                    
                    try {
                        $respuestaInventario = Http::timeout(10)->get($urlSistemaViejo);
                        
                        if ($respuestaInventario->successful()) {
                            // Extraemos la data. (Dependiendo de si tu Controller devuelve un array plano o envuelto en data)
                            // Si devuelve directo el array, usamos $respuestaInventario->json()
                            $datosJSON = $respuestaInventario->json();
                            
                            // Simplificamos la data antes de dársela a la IA para no confundirla con las tablas de $disponibilidades completas
                            $resumenParaIA = [
                                'total_fisico_general' => $datosJSON['general_fisico'] ?? 0,
                                'total_comprometido_viajes' => $datosJSON['general_comprometido'] ?? 0,
                                'disponible_para_nueva_venta' => $datosJSON['general_venta'] ?? 0,
                                'desglose_diesel' => [
                                    'disponible_total' => $datosJSON['totalDisponibleDiesel'] ?? 0,
                                    'comprometido' => $datosJSON['totalComprometidoDiesel'] ?? 0
                                ],
                                'desglose_mgo' => [
                                    'disponible_total' => $datosJSON['totalDisponibleMgo'] ?? 0,
                                    'comprometido' => $datosJSON['totalComprometidoMgo'] ?? 0
                                ]
                            ];

                            $contenidoHerramienta = json_encode($resumenParaIA);
                        } else {
                            $contenidoHerramienta = "Error: El sistema central devolvió un código " . $respuestaInventario->status();
                        }
                    } catch (\Exception $e) {
                        $contenidoHerramienta = "Error de red al intentar contactar al sistema central: " . $e->getMessage();
                    }

                    // 4. SEGUNDO ENVÍO: Devolvemos los datos gerenciales a la IA
                    $messages[] = $mensajeIA; 
                    $messages[] = [
                        "role" => "tool",
                        "tool_call_id" => $toolCall['id'],
                        "content" => (string)$contenidoHerramienta
                    ];

                    $payloadFase2 = [
                        "model" => $model,
                        "messages" => $messages,
                        "temperature" => 0.2
                    ];

                    $responseFase2 = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json'
                    ])->timeout(30)->post($url, $payloadFase2);

                    if ($responseFase2->successful()) {
                        $respuestaFinal = $responseFase2->json('choices.0.message.content');
                    } else {
                        Log::error('Error API Fase 2: ' . $responseFase2->body());
                        $respuestaFinal = 'Error al procesar las métricas financieras, señor.';
                    }

                } else {
                    $respuestaFinal = "El núcleo intentó ejecutar una herramienta no autorizada.";
                }

            } else {
                $respuestaFinal = $mensajeIA['content'] ?? 'Comando recibido, pero sin parámetros de acción.';
            }

            $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaFinal);
            return response()->json([
                'respuesta' => trim($respuestaLimpia)
            ]);

        } catch (\Exception $e) {
            Log::error('Exception C.O.R.E: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un fallo en los sistemas centrales.'
            ]);
        }
    }
}