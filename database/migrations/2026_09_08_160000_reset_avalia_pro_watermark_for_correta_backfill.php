<?php

use App\Models\ConfiguracaoSistema;
use Illuminate\Database\Migrations\Migration;

// Segunda migration de reset de watermark em poucos dias (ver
// 2026_09_08_140000_reset_avalia_watermark_for_resumo_backfill.php) — motivo
// diferente desta vez.
//
// A sincronização já rodou de novo depois daquele reset, mas ainda com a
// tentativa (errada) de derivar o gabarito pelo "consenso" de quem acertou —
// só depois descobrimos, com dado real, que o Avalia embaralha a ordem das
// alternativas por aluno (a MESMA questão teve as 5 letras diferentes
// marcadas como corretas por alunos diferentes), o que torna esse consenso
// inválido. A correção de verdade (`respostas.correta`, preenchido a partir
// de `answer_status`) só passa a valer pros dados JÁ sincronizados numa
// sincronização completa nova — daí o reset de novo, só pro Avalia Pro
// (Avalia Online nunca teve gabarito/correta derivável, nada muda pra ele).
return new class extends Migration
{
    public function up(): void
    {
        ConfiguracaoSistema::definir('avalia_watermark_notas_avalia_pro', null);
        ConfiguracaoSistema::definir('avalia_watermark_respostas_avalia_pro', null);
    }

    public function down(): void
    {
        // Nada a desfazer — resetar um watermark não é uma mudança de
        // schema, e não há como saber qual era o valor anterior.
    }
};
