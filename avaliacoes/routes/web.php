<?php

use App\Http\Controllers\Admin\ProvaController;
use App\Http\Controllers\Admin\QuestaoImportController;
use App\Http\Controllers\Admin\ResultadoImportController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::guard('admin')->check() ? 'provas.index' : 'login');
});

Route::middleware('guest:admin')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.attempt');
});

Route::middleware('auth:admin')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/provas', [ProvaController::class, 'index'])->name('provas.index');
    Route::post('/provas', [ProvaController::class, 'store'])->name('provas.store');
    Route::get('/provas/{prova}', [ProvaController::class, 'show'])->name('provas.show');

    Route::get('/provas/{prova}/questoes/import', [QuestaoImportController::class, 'create'])
        ->name('provas.questoes.import');
    Route::post('/provas/{prova}/questoes/import', [QuestaoImportController::class, 'store'])
        ->name('provas.questoes.import.store');

    Route::get('/provas/{prova}/resultados/import', [ResultadoImportController::class, 'create'])
        ->name('provas.resultados.import');
    Route::post('/provas/{prova}/resultados/import', [ResultadoImportController::class, 'store'])
        ->name('provas.resultados.import.store');
});
