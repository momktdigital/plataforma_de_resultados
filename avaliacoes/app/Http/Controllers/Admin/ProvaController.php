<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProvaRequest;
use App\Models\Prova;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProvaController extends Controller
{
    public function index(): View
    {
        $provas = Prova::withCount(['questoes', 'resultados'])
            ->latest('codigo')
            ->paginate(20);

        return view('admin.provas.index', ['provas' => $provas]);
    }

    public function store(StoreProvaRequest $request): RedirectResponse
    {
        $prova = Prova::create([
            ...$request->validated(),
            'criado_por' => Auth::guard('admin')->id(),
        ]);

        return redirect()
            ->route('provas.show', $prova)
            ->with('status', "Prova #{$prova->codigo} criada com sucesso.");
    }

    public function show(Prova $prova): View
    {
        $prova->loadCount(['questoes', 'resultados']);

        return view('admin.provas.show', ['prova' => $prova]);
    }
}
