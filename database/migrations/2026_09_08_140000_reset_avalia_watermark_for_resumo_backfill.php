<?php

use App\Models\ConfiguracaoSistema;
use Illuminate\Database\Migrations\Migration;

// Migration de dado, não de schema: zera o watermark do Avalia pra forçar a
// PRÓXIMA sincronização a reler tudo, não só o que mudou desde a última vez.
//
// Sem isto, uma instalação que já sincronizou dados antes desta versão
// ficaria travada pra sempre com o comportamento antigo pra tudo que já foi
// lido: AvaliaSyncService só busca linhas com cdc_datetime > watermark, e o
// watermark já avançou até "agora" na última sincronização — então mesmo
// forçando "Sincronizar" de novo, zero linhas voltam do Redshift e nada é
// reprocessado. Isso escondia justamente as duas correções mais recentes:
// (1) resultado_resumos (acertos/total/ausente) nunca era gerado pra
// avaliação do Avalia porque AvaliaSyncService não chamava
// ResumoResultadoService; (2) questoes.gabarito ficava fixo em '-' porque
// era só um placeholder, nunca derivado do consenso de respostas corretas.
// As duas ficam corrigidas no código, mas só passam a valer pros dados JÁ
// sincronizados se uma sincronização completa rodar de novo.
//
// Upsert já é idempotente — reler tudo não duplica nada, só custa mais uma
// vez (mesmo raciocínio de AvaliaSyncService::resetarWatermark()).
return new class extends Migration
{
    public function up(): void
    {
        foreach (['avalia_pro', 'avalia_online'] as $produto) {
            ConfiguracaoSistema::definir("avalia_watermark_notas_{$produto}", null);
            ConfiguracaoSistema::definir("avalia_watermark_respostas_{$produto}", null);
        }
    }

    public function down(): void
    {
        // Nada a desfazer — resetar um watermark não é uma mudança de
        // schema, e não há como saber qual era o valor anterior.
    }
};
