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

        // Ahora usamos esta variable genérica en el .env
        $apiKey = config('services.apikey.openai');
        
        if (!$apiKey) {
            return response()->json(['respuesta' => 'Error de sistema. Falta la credencial API universal.']);
        }

        // 1. LA PERSONALIDAD
        $systemInstruction = "Eres C.O.D.E., el Asistente Corporativo de Alta Gerencia de la empresa Impordiesel. Eres sumamente profesional, conciso, directo y te diriges al usuario como 'Señor' o 'Director'. Responde siempre en español, de forma ejecutiva y sin rodeos. Tu respuesta será dictada por un sintetizador de voz, así que NO uses emojis, NO uses asteriscos, NO uses viñetas raras ni formatos markdown. Usa comas y puntos claros para las pausas de respiración.";

        // 2. LA URL Y EL MODELO (AQUÍ ESTÁ LA MAGIA)
        // Opción A: GROQ (El más rápido del mundo, Llama 3, plan gratis generoso)
        $url = "https://api.groq.com/openai/v1/chat/completions";
        $model = "llama3-8b-8192"; 

        // Opción B: OPENAI (Descomenta estas dos líneas si prefieres usar llave de ChatGPT)
        // $url = "https://api.openai.com/v1/chat/completions";
        // $model = "gpt-4o-mini";

        // 3. EL PAYLOAD (Estándar de la industria)
        $payload = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemInstruction],
                ["role" => "user", "content" => $texto]
            ],
            "temperature" => 0.2
        ];

        try {
            // 4. LA LLAMADA BLINDADA
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json'
            ])
            ->timeout(30)
            ->retry(2, 1000, function ($exception, $request) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException || 
                       ($exception->response && $exception->response->serverError());
            })
            ->post($url, $payload);

            if ($response->successful()) {
                // Sacamos la respuesta de la estructura estándar (choices.0.message.content)
                $respuestaIA = $response->json('choices.0.message.content');
                
                // Limpieza de caracteres rebeldes
                $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaIA);
                
                return response()->json([
                    'respuesta' => trim($respuestaLimpia)
                ]);
            } else {
                Log::error('Error API (Status ' . $response->status() . '): ' . $response->body());
                
                if ($response->status() === 429) {
                    return response()->json(['respuesta' => 'Señor, hemos agotado la cuota de procesamiento gratuito.']);
                }

                return response()->json(['respuesta' => 'Mis sensores indican un fallo de conexión con el núcleo cognitivo.']);
            }
        } catch (\Exception $e) {
            Log::error('Exception C.O.D.E: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un error crítico en el servidor interno.'
            ]);
        }
    }
}