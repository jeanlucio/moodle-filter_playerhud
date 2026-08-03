# 🔐 Segurança e Conformidade

* **Controle de acesso baseado em capability:** todo shortcode reverifica `block/playerhud:view`
  no momento da renderização — um usuário sem essa capability vê o shortcode totalmente
  removido, o mesmo que ocorre com um convidado ou um jogador pausado, mesmo que as checagens
  determinísticas (login, curso, status de gamificação) passem.
* **Aplicação no servidor:** tempo de recarga (cooldown) e limites de coleta são sempre
  validados no servidor; o estado visual do shortcode (pronto/cooldown/coletado) é apenas uma
  questão de exibição, nunca a barreira real.
* **Proteção `require_sesskey()`:** os endpoints de coleta e processamento de troca aos quais os
  shortcodes apontam (`collect.php`, `process_trade.php`, ambos pertencentes ao Bloco
  PlayerHUD) exigem uma chave de sessão válida em toda requisição.
* **Desserialização segura:** o `configdata` armazenado pelo bloco é lido com
  `unserialize_object()`, restringindo o payload a `stdClass` — uma configuração forjada nunca
  pode disparar instanciação arbitrária de objeto nem uma cadeia POP-gadget como um
  `unserialize()` puro permitiria.
* **Renderização protegida contra XSS:** descrições de item e de classe RPG são sempre
  higienizadas com `format_text()` antes de serem entregues ao cliente, e todo fallback de
  ícone/emoji nos templates Mustache usa saída com escape (double-mustache) — uma descrição já
  foi enviada crua em um dos caminhos de renderização; isso foi corrigido e está coberto por um
  teste de regressão dedicado.
* **Proteção contra reentrância de shortcode:** uma descrição de item ou de classe RPG contendo
  o próprio shortcode `[PLAYERHUD_DROP ...]`/`[PLAYERHUD_WIDGET]` do filtro é renderizada com
  `format_text(..., ['filter' => false])`, impedindo que ela reentre em
  `text_filter::filter()` e recurse até a requisição esgotar seu limite de memória — a própria
  cadeia de filtros do Moodle não possui proteção de reentrância.
* **Zero N+1 por construção:** o pré-carregamento em lote (e não consultas por shortcode)
  impede que uma página com muitos drops se torne um vetor de negação de serviço por
  amplificação de consultas.
* **Compatível com a API Externa do Moodle:** o fluxo de coleta ao qual ele se conecta é
  exposto como uma função externa própria, com validação de parâmetros/retorno e checagem de
  capability.
* **Consciente de privacidade:** veja [Provedor de Privacidade](#privacy-provider) abaixo —
  este plugin não armazena dado nenhum próprio.
* **Compatível com dispositivos móveis:** os shortcodes degradam com segurança dentro do app do
  Moodle, em vez de tentar um fluxo AJAX que o app não suporta.

## Provedor de Privacidade

O Filtro PlayerHUD implementa o `null_provider` do Moodle — ele apenas **exibe** dados
pertencentes e armazenados pelo Bloco PlayerHUD (itens, drops, trocas, inventário); nunca
persiste nenhum dado pessoal próprio. Veja a documentação do próprio bloco para a cobertura
completa de exportação/exclusão GDPR.
