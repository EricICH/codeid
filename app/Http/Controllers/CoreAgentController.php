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

        // 1. LA PERSONALIDAD (SYSTEM INSTRUCTION)
        $systemInstruction = "Eres C.O.D.E., el Asistente Corporativo de Alta Gerencia de la empresa Impordiesel. Eres sumamente profesional, conciso, directo y te diriges al usuario como 'Señor' o 'Director'. Responde siempre en español, de forma ejecutiva y sin rodeos. Tu respuesta será dictada por un sintetizador de voz, así que NO uses emojis, NO uses asteriscos, NO uses viñetas raras ni formatos markdown. Usa comas y puntos claros para las pausas de respiración.";

        // Usamos Gemini 1.5 Flash porque para voz necesitamos que responda RÁPIDO (baja latencia)
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro-latest:generateContent?key={$apiKey}";

        // 2. ARMAMOS EL PAQUETE DE DATOS
        $payload = [
            "system_instruction" => [
                "parts" => [
                    ["text" => $systemInstruction]
                ]
            ],
            "contents" => [
                [
                    "parts" => [
                        ["text" => $texto]
                    ]
                ]
            ],
            // Configuramos la temperatura baja (0.2) para que sea analítico y no invente tonterías
            "generationConfig" => [
                "temperature" => 0.2
            ]
        ];

        try {
            // 3. ENVIAMOS LA PETICIÓN A GOOGLE
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post($url, $payload);

            if ($response->successful()) {
                // Extraemos el texto de la respuesta mutante en JSON de Google
                $respuestaIA = $response->json('candidates.0.content.parts.0.text');
                
                // Limpieza de emergencia por si la IA se pone rebelde con el formato
                $respuestaLimpia = str_replace(['*', '#', '_', '`'], '', $respuestaIA);
                
                return response()->json([
                    'respuesta' => trim($respuestaLimpia)
                ]);
            } else {
                Log::error('Error Gemini: ' . $response->body());
                return response()->json([
                    'respuesta' => 'Mis sensores indican un fallo de comunicación con los servidores de Google, señor.'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception C.O.D.E: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'Ocurrió un error crítico en el servidor interno.'
            ]);
        }
    }
}