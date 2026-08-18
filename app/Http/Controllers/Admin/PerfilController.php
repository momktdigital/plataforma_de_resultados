<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSenhaRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function edit(): View
    {
        return view('admin.perfil.edit');
    }

    public function updateSenha(UpdateSenhaRequest $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        $admin->password_hash = Hash::make($request->validated('new_password'));
        $admin->save();

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Senha alterada com sucesso! Utilize a nova senha no próximo login.');
    }
}
