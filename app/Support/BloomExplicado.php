<?php

namespace App\Support;

/**
 * Traduz um nível da Taxonomia de Bloom ("Aplicação", "Analisar", "Avaliar"...) em linguagem de estudante: o que
 * aquele tipo de questão pede e como treinar. Os níveis vêm de planilha em texto livre, então o casamento é pelo
 * radical da palavra, sem acento nem caixa — "Aplicação", "Aplicar" e "3 - aplicar" são o mesmo nível. Um nível que
 * não reconhecemos devolve null (o chamador usa um texto genérico com o nome que veio da planilha).
 */
final class BloomExplicado
{
    /** radical => [o que a questão pede, como treinar]. A ordem importa: o primeiro que casar vale. */
    private const NIVEIS = [
        '/(lembr|memor|reconhec|conhecimento)/' => [
            'lembrar conceitos, definições e fatos',
            'Reforce a base: resumos, mapas mentais e flashcards ajudam a fixar.',
        ],
        '/(compreen|entend|interpret|explic)/' => [
            'explicar e interpretar o conteúdo com as próprias palavras',
            'Experimente explicar o assunto em voz alta, como se o ensinasse a um colega.',
        ],
        '/aplic/' => [
            'usar o conteúdo numa situação prática',
            'Treine com casos e exercícios, não só com teoria.',
        ],
        '/analis/' => [
            'relacionar dados de um caso e separar o que é relevante',
            'Pratique com casos: identifique os dados principais e relacione cada um ao conteúdo, além do estudo teórico.',
        ],
        '/(avali|julg)/' => [
            'julgar e justificar a melhor conduta entre várias opções',
            'Compare condutas e justifique por que descartar cada alternativa.',
        ],
        '/(cria|criac|sintet|elabor|propor)/' => [
            'propor uma solução ou montar um plano a partir do que você sabe',
            'Treine montando planos completos a partir de casos, do diagnóstico à conduta.',
        ],
    ];

    /** @return array{pede: string, dica: string}|null */
    public static function para(string $nivel): ?array
    {
        $normalizado = HeaderResolver::normalize($nivel);

        foreach (self::NIVEIS as $padrao => [$pede, $dica]) {
            if (preg_match($padrao, $normalizado) === 1) {
                return ['pede' => $pede, 'dica' => $dica];
            }
        }

        return null;
    }
}
