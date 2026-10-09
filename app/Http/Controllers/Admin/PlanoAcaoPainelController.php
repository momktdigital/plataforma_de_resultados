<?php

namespace App\Http\Controllers\Admin;

use App\Models\Admin;
use App\Models\PlanoAcao;
use App\Services\PlanoAcaoService;
use App\Support\NomeCurso;

/**
 * Base dos controllers de plano de ação do coordenador: quem pode gravar e de quais planos ele é dono.
 */
abstract class PlanoAcaoPainelController extends PainelController
{
    /** O coordenador que pode GRAVAR: o reitor olhando o curso (e o administrador, sem curso) só leem. */
    protected function coordenadorQueEscreve(): Admin
    {
        $usuario = $this->coordenador();
        abort_if($usuario === null || $usuario->emVisaoDeCurso, 403, 'Seu perfil não pode criar nem alterar planos de ação.');

        return $usuario;
    }

    /** @param  array<int, int>  $codigos  @return array<int, int> ver PlanoAcaoService::avaliacoesAcessiveis() */
    protected function avaliacoesAcessiveis(Admin $usuario, array $codigos): array
    {
        return PlanoAcaoService::avaliacoesAcessiveis($usuario, $codigos);
    }

    /** O coordenador (ou o reitor na visão do curso) e o plano de um curso dele; qualquer outro curso responde 404. */
    protected function doCoordenador(PlanoAcao $plano): Admin
    {
        $usuario = $this->coordenador();
        abort_if($usuario === null || ! NomeCurso::estaEm($plano->curso, $usuario->cursos()), 404);

        return $usuario;
    }
}
