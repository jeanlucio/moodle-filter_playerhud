# ✨ Funcionalidades

* 📍 **Drops de Itens:** insere drops de itens colecionáveis diretamente no conteúdo do curso —
  páginas, rótulos, livros, fóruns e qualquer outra atividade com suporte a HTML.
* 🔢 **Exibição de Quantidade por Coleta:** o card do drop mostra o número real de unidades
  concedidas por coleta (ex.: `x2`), lido através do motor de quantidade de item do bloco
  quando presente, com fallback pra exibição de unidade única numa versão mais antiga do bloco.
* 🏪 **Widgets de Troca:** incorpora um card de troca da loja NPC do PlayerHUD embutido no
  conteúdo, resolvido por um código curto de consulta em vez de um ID de banco de dados cru.
* 🧩 **Integração Baseada em Shortcode:** três shortcodes (`[PLAYERHUD_WIDGET]`,
  `[PLAYERHUD_DROP ...]`, `[PLAYERHUD_TRADE ...]`) — veja [Como Usar](#usage) para a sintaxe
  completa.
* 🎮 **Widget Compacto do HUD:** o mesmo HUD do jogador exibido no bloco (avatar, XP, nível,
  itens recentes, ranking, barra de karma), incorporável em qualquer lugar que aceite shortcode.
* ⚡ **Interação em Tempo Real:** coleta baseada em AJAX via `core/ajax` do Moodle, sem
  redirecionamento de página.
* 🎒 **Integração Transparente com o Inventário:** itens coletados entram diretamente no sistema
  de inventário do PlayerHUD, respeitando as mesmas regras de tempo de recarga, limite e item
  secreto do bloco.
* 🚀 **Renderização sem N+1:** todo código de drop/troca em uma página é carregado em lote em
  uma única passagem antes da renderização, independente de quantos shortcodes existam no mesmo
  conteúdo.
* 🔐 **Validação no Servidor:** tempo de recarga (cooldown), limites de coleta, opt-out da
  gamificação e a capability `block/playerhud:view` são todos aplicados no momento da
  renderização — um shortcode nunca vaza nome de item, XP ou conteúdo de troca para um usuário
  que não deveria ver.
* 📱 **Renderização Compatível com Dispositivos Móveis:** shortcodes renderizam um fallback leve
  (ou nada, no caso do widget) dentro do app do Moodle, onde o fluxo de coleta via AJAX não se
  aplica.
