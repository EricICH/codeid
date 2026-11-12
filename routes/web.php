<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CoreAgentController;

// La vista principal (ahora es la raíz)
Route::get('/', function () {
    return view('index');
});

// El endpoint de la IA
Route::post('/api/hablar', [CoreAgentController::class, 'procesarComando']);