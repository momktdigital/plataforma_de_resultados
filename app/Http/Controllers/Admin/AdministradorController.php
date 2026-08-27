<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdministradorRequest;
use App\Http\Requests\UpdateAdministradorRequest;
use App\Models\Admin;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdministradorController extends Controller
{
    public function index(): View
    {
        $admins = Admin::orderBy('id')->get();

        return view('admin.administradores.index', ['admins' => $admins]);
    }

    public function store(StoreAdministradorRequest $request): RedirectResponse
    {
        $admin = Admin::create([
            'username' => $request->validated('username'),
            'email' => $request->validated('email'),
            'password_hash' => Hash::make($request->validated('password')),
        ]);

        AtividadeLogger::registrar('administrador.criado', 'Admin', $admin->id, ['username' => $admin->username]);

        return redirect()
            ->route('administradores.index')
            ->with('status', "Administrador '{$request->validated('username')}' criado com sucesso.");
    }

    public function edit(Admin $admin): View
    {
        return view('admin.administradores.edit', ['admin' => $admin]);
    }

    public function update(UpdateAdministradorRequest $request, Admin $admin): RedirectResponse
    {
        $usernameAntes = $admin->username;

        $admin->username = $request->validated('username');
        $admin->email = $request->validated('email');

        $senhaRedefinida = ! empty($request->validated('password'));
        if ($senhaRedefinida) {
            $admin->password_hash = Hash::make($request->validated('password'));
        }

        $admin->save();

        AtividadeLogger::registrar('administrador.editado', 'Admin', $admin->id, [
            'username_antes' => $usernameAntes,
            'username_depois' => $admin->username,
            'senha_redefinida' => $senhaRedefinida,
        ]);

        return redirect()
            ->route('administradores.index')
            ->with('status', "Administrador '{$admin->username}' atualizado com sucesso.");
    }

    public function destroy(Admin $admin): RedirectResponse
    {
        if ($admin->id === Auth::guard('admin')->id()) {
            return back()->withErrors(['admin' => 'Você não pode excluir a sua própria conta logada.']);
        }

        $username = $admin->username;
        $admin->delete();

        AtividadeLogger::registrar('administrador.excluido', 'Admin', $admin->id, ['username' => $username]);

        return redirect()->route('administradores.index')->with('status', 'Administrador excluído com sucesso.');
    }
}
