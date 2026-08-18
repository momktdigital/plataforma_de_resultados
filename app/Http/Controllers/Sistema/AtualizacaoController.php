<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Update\UpdateService;
use Illuminate\View\View;

class AtualizacaoController extends Controller
{
    public function index(UpdateService $service): View
    {
        return view('admin.sistema.atualizacao', [
            'versaoAtual' => $service->versaoAtual(),
            'disponivel' => $service->verificarAtualizacao(),
        ]);
    }

    public function store(UpdateService $service): View
    {
        $resultado = $service->atualizar();

        return view('admin.sistema.atualizacao-resultado', ['resultado' => $resultado]);
    }
}
