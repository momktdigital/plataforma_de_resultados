<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Models\Atividade;
use Illuminate\View\View;

class AtividadeController extends Controller
{
    public function index(): View
    {
        $atividades = Atividade::latest('id')->paginate(50);

        return view('admin.sistema.atividades', ['atividades' => $atividades]);
    }
}
