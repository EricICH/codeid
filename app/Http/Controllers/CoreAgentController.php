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

        $apiKey = env('GEMINI_API_KEY');
        
        if (!$apiKey) {
            return response()->json(['respuesta' => 'Error de sistema. El núcleo cognitivo carece de credenciales API.']);
        }

        // 1. LA PERSONALIDAD
        $systemInstruction = "Eres C.O.R.E., el Asistente Corporativo de Alta Gerencia de la empresa Impordiesel. Eres sumamente profesional, conciso, directo y te diriges al usuario como 'Señor' o 'Director'. Responde siempre en español, de forma ejecutiva y sin rodeos. Tu respuesta será dictada por un sintetizador de voz, así que NO uses emojis, NO uses asteriscos, NO uses viñetas raras ni formatos markdown. Usa comas y puntos claros para las pausas de respiración.\n\n--- INICIO DE COMANDO DEL DIRECTOR ---\n";

        // 2. NUEVO ENDPOINT DE LA INTERACTIONS API (2026)
        $url = "https://generativelanguage.googleapis.com/v1beta/interactions";

        // 3. NUEVO PAYLOAD Y MODELO (gemini-3.8-flash)
        $payload = [
            "model" => "gemini-3.8-live",
            "input" => $systemInstruction . $texto,
        ];  

        try {
            // 4. NUEVA FORMA DE ENVIAR LA LLAVE CON TOLERANCIA A FALLOS
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey
            ])
            ->timeout(60) // Le damos 60 segundos a PHP para que no tire el cURL error 28
            ->retry(3, 2000, function ($exception, $request) {
                // Si Google dice que está saturado (503) o da error de servidor (500), 
                // Laravel esperará 2 segundos y volverá a intentar automáticamente hasta 3 veces.
                return $exception instanceof \Illuminate\Http\Client\ConnectionException || 
                       ($exception->response && $exception->response->serverError());
            })
            ->post($url, $payload);

            if ($response->successful()) {
                // Extraemos el texto de la nueva estructura de la Interactions API
                $respuestaIA = $response->json('output_text');
                
                // Limpieza de emergencia por si la IA se pone rebelde con el formato
                $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaIA);
                
                return response()->json([
                    'respuesta' => trim($respuestaLimpia)
                ]);
            } else {
                Log::error('Error Gemini: ' . $response->body());
                return response()->json([
                    'respuesta' => 'Mis sensores indican que los servidores centrales están congestionados, señor. Por favor, intente en unos minutos.'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception C.O.R.E: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un error crítico en el servidor interno al procesar su solicitud.'
            ]);
        }
    }
}