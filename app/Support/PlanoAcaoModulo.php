<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\ConfiguracaoSistema;

/**
 * Chave liga/desliga do módulo Plano de ação (Configurações → Plano de ação, só administrador):
 *
 *  - `ativo`      — todos os perfis que já têm o módulo (coordenador, colaborador, administrador, reitor) o enxergam; é o padrão;
 *  - `teste`      — só os usuários escolhidos (`plano_acao_usuarios`, ids de `admins`) o enxergam: serve para experimentar com um
 *                   grupo pequeno antes de liberar. NÃO inclui o administrador automaticamente: se ele quiser testar, entra na lista;
 *  - `desativado` — ninguém o enxerga, nem o administrador (a configuração, que mora em Configurações, continua acessível).
 *
 * "Enxergar" = o item de menu, a aba do coordenador, o ícone "Iniciar plano de ação" dos gráficos e as rotas (404, como se o
 * módulo não existisse — ver o middleware `modulo-plano`). Os planos já gravados ficam no banco, intactos, e voltam quando o
 * módulo é religado.
 *
 * O estado é lido uma vez por requisição (o ícone aparece dezenas de vezes por tela) e guardado nos atributos da própria
 * requisição, então nunca vaza entre requisições nem entre testes.
 */
final class PlanoAcaoModulo
{
    public const ATIVO = 'ativo';

    public const TESTE = 'teste';

    public const DESATIVADO = 'desativado';

    public const CHAVE_MODO = 'plano_acao_modo';

    public const CHAVE_USUARIOS = 'plano_acao_usuarios';

    /** @return array<int, string> */
    public static function modos(): array
    {
        return [self::ATIVO, self::TESTE, self::DESATIVADO];
    }

    public static function modo(): string
    {
        return self::estado()['modo'];
    }

    /** @return array<int, int> ids de `admins` liberados no modo de teste */
    public static function usuarios(): array
    {
        return self::estado()['usuarios'];
    }

    /** O módulo está ligado para alguém (ativo ou em teste)? Quem roda fora de uma sessão (lembretes) usa isto. */
    public static function ligado(): bool
    {
        return self::modo() !== self::DESATIVADO;
    }

    public static function liberadoPara(?Admin $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        return match (self::modo()) {
            self::ATIVO => true,
            self::TESTE => in_array((int) $usuario->getKey(), self::usuarios(), true),
            default => false,
        };
    }

    /** @param array<int, int|string> $usuarios */
    public static function salvar(string $modo, array $usuarios): void
    {
        $modo = in_array($modo, self::modos(), true) ? $modo : self::ATIVO;
        $ids = array_values(array_unique(array_filter(array_map('intval', $usuarios), fn ($id) => $id > 0)));
        sort($ids);

        ConfiguracaoSistema::definir(self::CHAVE_MODO, $modo);
        ConfiguracaoSistema::definir(self::CHAVE_USUARIOS, implode(',', $ids));
        request()->attributes->remove('plano_acao_modulo');
    }

    /** @return array{modo: string, usuarios: array<int, int>} */
    private static function estado(): array
    {
        $atributos = request()->attributes;
        if ($atributos->has('plano_acao_modulo')) {
            return $atributos->get('plano_acao_modulo');
        }

        $modo = (string) ConfiguracaoSistema::valor(self::CHAVE_MODO, self::ATIVO);
        $usuarios = array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,;]+/', (string) ConfiguracaoSistema::valor(self::CHAVE_USUARIOS, ''), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            fn ($id) => $id > 0,
        )));

        $estado = ['modo' => in_array($modo, self::modos(), true) ? $modo : self::ATIVO, 'usuarios' => $usuarios];
        $atributos->set('plano_acao_modulo', $estado);

        return $estado;
    }
}
