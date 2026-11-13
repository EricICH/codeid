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
        $historial = $request->input('historial', []); // Recibimos la memoria

        if (!$texto) {
            return response()->json(['respuesta' => 'No logré escuchar el comando, señor.']);
        }

        Log::info('--- COMANDO: ' . $texto . ' ---');

        $apiKey = env('OPENAI_API_KEY');
        
        // EL NUEVO CEREBRO: Instrucciones condicionadas y estrictas
        $systemInstruction = "Eres C.O.R.E., el Asistente Corporativo de Impordiesel. Eres profesional, analítico y hablas de forma conversacional con el 'Director'. 
        
       REGLAS DE ORO (ESTRICTAS PARA AUDIO):
        1. Tu respuesta será dictada por un sintetizador de voz. NUNCA uses tablas (|), asteriscos (*), viñetas, guiones, corchetes, ni formatos Markdown. Habla en prosa natural, usando comas, puntos y conectores discursivos (por ejemplo, 'además', 'sin embargo', 'por otro lado').
        2. No satures al Director leyendo todos los números de un arreglo. Extrae la conclusión.
        3. Si te piden un resumen general, NO desgloses los tanques. Habla del volumen físico total, el comprometido, y el libre para ventas, y añade un breve análisis de salud del inventario (ej. 'Tenemos poca disponibilidad de Diésel').
        4. Si te piden detalles de los tanques, agrupa los datos. Ejemplo: 'Tenemos 7 tanques. El de mayor volumen es el tanque X con Y litros. Los demás están casi vacíos o por debajo del nivel de alerta.'
        5. Tienes memoria. Si el Director hace una pregunta de seguimiento, continúa el hilo de la conversación sin repetir el reporte inicial.
        
        CAPACIDADES DE ANÁLISIS DE LA HERRAMIENTA:
        Cuando recibas los datos de la herramienta 'consultar_metricas_dashboard', tendrás acceso a una matriz profunda. Úsala con inteligencia:
        - Si el Director pregunta por 'Precargas' o 'Vehículos', busca en el arreglo 'vehiculosPrecargados'. Menciona la placa, modelo y cantidad, y explica qué significa eso para la logística del día.
        - Si te preguntan por 'Despachos' o 'Compras teóricas', cruza los datos de 'totalDespachosTeorico' con las compras y explica el flujo de caja logístico.
        - Si un tanque (en 'disponibilidades.data') tiene su 'nivel_actual_litros' por debajo de su 'nivel_alerta_litros', puedes advertir al Director sobre un posible quiebre de stock si él pide detalles de infraestructura.
        
        TONO: Ejecutivo, seguro, rápido. Como un Jefe de Operaciones brillante. Si no sabes algo, dile que no tienes acceso a esa métrica específica, pero no inventes datos.";
       
        $url = "https://api.groq.com/openai/v1/chat/completions";
        $model = "openai/gpt-oss-20b"; 

        // ARMAMOS LA CONVERSACIÓN CON LA MEMORIA
        $messages = [
            ["role" => "system", "content" => $systemInstruction]
        ];

        // Inyectamos el historial (omitiendo el último mensaje del usuario porque lo agregamos abajo)
        $historialFiltrado = array_slice($historial, 0, -1);
        foreach ($historialFiltrado as $msg) {
            // Solo aceptamos user y assistant para evitar que Groq rechace roles raros
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = ["role" => $msg['role'], "content" => $msg['content']];
            }
        }

        // Agregamos el comando actual
        $messages[] = ["role" => "user", "content" => $texto];


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
                
                Log::info('Paso 3: Llamando a la API de Impordiesel: ' . $urlSistemaViejo);
                $respuestaInventario = Http::timeout(10)->get($urlSistemaViejo);
                
                if ($respuestaInventario->successful()) {
                    Log::info('Paso 4: Datos obtenidos con éxito de la API de Impordiesel.');
                    
                    // AHORA LE PASAMOS EL JSON COMPLETO SIN FILTRAR
                    // Para que pueda analizar tanques, vehículos, mermas y capacidades.
                    $datosJSON = $respuestaInventario->json();
                    
                    // Opcional: Si el arreglo es muy, muy grande (más de 30 tanques), 
                    // a veces ayuda eliminar campos inútiles como 'orden_x', 'orden_z', 'rotacion', 
                    // para ahorrar tokens de Groq, pero con 7 tanques, pásalo completo.
                    
                    $contenidoHerramienta = json_encode($datosJSON);
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