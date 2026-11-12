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
            return response()->json(['respuesta' => 'Error de sistema. Falta la credencial API.']);
        }

        // 1. LA PERSONALIDAD
        $systemInstruction = "Eres C.O.D.E., el Asistente Corporativo de Alta Gerencia de la empresa Impordiesel. Eres sumamente profesional, conciso, directo y te diriges al usuario como 'Señor' o 'Director'. Responde siempre en español, de forma ejecutiva y sin rodeos. Tu respuesta será dictada por un sintetizador de voz, así que NO uses emojis, NO uses asteriscos, NO uses viñetas raras ni formatos markdown. Usa comas y puntos claros para las pausas de respiración.\n\n--- COMANDO ---\n";

        // 2. LA URL NUEVA (Si dejas la vieja aquí, va a explotar)
        // Usamos también el query parameter ?key= por si tu servidor bloquea headers raros.
        $url = "https://generativelanguage.googleapis.com/v1beta/interactions";

        // 3. EL PAYLOAD NUEVO (Usamos gemini-4-carbon, el modelo estándar comprobado de la doc)
        $payload = [
            "model" => "gemini-4-carbon",
            "input" => $systemInstruction . $texto
        ];

        try {
            // 4. LA LLAMADA BLINDADA
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey
            ])
            ->timeout(60)
            ->retry(3, 2000, function ($exception, $request) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException || 
                       ($exception->response && $exception->response->serverError());
            })
            ->post($url, $payload);

            if ($response->successful()) {
                // Sacamos la respuesta de la Interactions API
                $respuestaIA = $response->json('output_text');
                
                // Limpieza de caracteres que enloquecen al bot de voz
                $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaIA);
                
                return response()->json([
                    'respuesta' => trim($respuestaLimpia)
                ]);
            } else {
                Log::error('Error Gemini (Status ' . $response->status() . '): ' . $response->body());
                return response()->json([
                    'respuesta' => 'Mis sensores indican que los servidores están congestionados. Fallo de conexión.'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception C.O.R.E: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un error crítico en el servidor interno.'
            ]);
        }
    }
}