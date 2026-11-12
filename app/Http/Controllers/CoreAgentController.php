<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CoreAgentController extends Controller
{
    public function procesarComando(Request $request)
    {
        $texto = $request->input('mensaje');

        // Aquí luego meteremos la conexión con Gemini
        $respuestaIA = "He recibido tu orden: '{$texto}'. Mis sistemas están en línea en el nuevo servidor, señor.";
        
        return response()->json([
            'respuesta' => $respuestaIA
        ]);
    }
}