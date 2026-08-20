# 🧪 Testes Automatizados

O filtro inclui testes unitários/integração (PHPUnit) e de aceitação em navegador (Behat),
executados a cada push de CI contra a matriz completa (Moodle 4.5 → 5.2, PostgreSQL & MariaDB).

### PHPUnit — Testes Unitários e de Integração

| Arquivo de teste | Casos | O que é coberto |
|-------------------|------:|----------------|
| `filter_test.php` | 27 | Parsing do shortcode no botão de coleta; zero queries N+1 com 5 shortcodes de drop simultâneos na mesma página; visibilidade do drop com gamificação pausada; string de motivo do provedor nulo de privacidade; shortcodes removidos para convidados/página inicial do site e para curso sem instância do bloco; o fragmento de modais dos assets renderiza HTML de verdade; um item secreto renderiza como placeholder de mistério até ser coletado; uma descrição de item nula não trava `base64_encode()`; estados de limite atingido e cooldown do drop renderizam desabilitados sem ação de coleta; um código de troca válido resolve e renderiza o card, um código desconhecido não renderiza nada (proteção contra enumeração de ID); o widget mostra um botão de opt-in quando pausado e o HUD completo quando ativo; o app do Moodle redireciona para a Mochila do bloco em vez de renderizar gatilhos AJAX; uma descrição de item com payload de XSS é higienizada com `format_text()` antes de ser codificada em base64 no `data-desc-b64`; um payload `configdata` forjado é desserializado com `unserialize_object()`, então o `__wakeup()` de uma classe arbitrária nunca dispara; os templates Mustache de drop e troca escapam conteúdo de ícone/emoji que não seja imagem (double-mustache); um usuário com `block/playerhud:view` explicitamente proibido nunca vê shortcodes renderizados; uma descrição contendo o próprio shortcode `[PLAYERHUD_DROP ...]` do filtro não é reexpandida, provando a proteção de reentrância sem disparar a recursão ilimitada real que ela evita; o limite de coleta de um drop é atingido contando *eventos* contra o `stack_log` do motor novo, não unidades concedidas, e um drop com `value > 1` continua disponível após um único evento que concedeu várias unidades de uma vez; `render_trade()` reconhece saldo suficiente mantido só na tabela `stack` do motor novo, não apenas nas linhas legadas do inventário; chamar `filter()` duas vezes para o mesmo drop numa única requisição não duplica o progresso de coleta |
| **Total** | **27** | |

```bash
vendor/bin/phpunit --testsuite filter_playerhud
```

**Cobertura de linhas por classe** (PHPUnit + Xdebug):

| Classe | Cobertura de linhas |
|--------|:-------------------:|
| `output\assets` | 100% |
| `privacy\provider` | 100% |
| `text_filter` | 83% |
| `output\render` | 82% |
| `output\widget` | 73% |
| **Total** | **79%** |

O número mais baixo, `output\widget` (73%), reflete ramos que a fixture atual dos testes nunca
exercita, e não lógica sem teste: o jogador mínimo criado nos testes não tem classe RPG, nem
progresso de karma, nem grupo do `mod_playergroup`, então os ramos de
retrato/barra-de-karma/descrição-de-classe e badge de grupo de `export_for_template()` não são
alcançados. As linhas não cobertas de `output\render` são majoritariamente a checagem de
restrição por classe RPG (o ramo de `is_item_visible_for_class()` dentro de `render_drop()`) e
o caminho completo de saldo de `render_trade()` com um inventário real e não vazio.

### Behat — Testes de Aceitação

| Arquivo de feature | Cenários | O que é coberto |
|--------------------|--------:|----------------|
| `filter_playerhud_modals.feature` | 6 | Coletar um drop pelo shortcode via AJAX não redireciona a página; abrir o detalhe do item a partir do estoque do widget após coletar; o modal de descrição não renderiza tags HTML cruas como texto visível; um payload de XSS na descrição do item não vaza para o HTML real do modal (`onerror`, `<script`) — a regressão no nível de HTML para o achado corrigido em `render_drop()`; o modal nunca mostra placeholders de string `[[...]]` crus; clicar no gatilho do item várias vezes nunca duplica o modal no DOM |
| **Total** | **6** | |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@filter_playerhud --profile=chrome
```
