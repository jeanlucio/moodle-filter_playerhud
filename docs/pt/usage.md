# 📖 Como Usar

1. Certifique-se de que o Bloco PlayerHUD esteja adicionado e configurado no curso.
2. Ative o **Filtro PlayerHUD** (veja [Instalação](#installation)).
3. Insira um dos shortcodes abaixo em qualquer área de conteúdo que passe pelos filtros do
   Moodle — páginas, rótulos, capítulos de livro, posts de fórum, etc.
4. Os drops de itens e os widgets de troca são renderizados dinamicamente dentro do curso; os
   estudantes coletam/trocam conforme as regras definidas no Painel de Gerenciamento do Bloco
   PlayerHUD.

Os shortcodes são removidos (renderizados como vazio) para convidados, na página inicial do
site, para um usuário cuja capability `block/playerhud:view` esteja proibida, e para um
estudante que tenha pausado sua própria gamificação — em todos esses casos nada sobre o item ou
a troca subjacente é enviado ao navegador.

## Referência de Shortcodes

### `[PLAYERHUD_WIDGET]`

Renderiza o widget compacto do PlayerHUD: avatar, XP/nível, estoque de itens recentes, badge de
ranking e (quando o modo RPG está ativado) a barra de karma e o retrato da classe. Não recebe
atributos.

```
[PLAYERHUD_WIDGET]
```

### `[PLAYERHUD_DROP code=... mode=... text=... button_text=... button_emoji=...]`

Renderiza um gatilho de coleta de item.

| Atributo | Obrigatório | Valores | Padrão | Descrição |
|----------|:-----------:|---------|--------|-----------|
| `code` | Sim | Alfanumérico | — | O código único de coleta do drop, gerado ao criar o drop no Painel de Gerenciamento. |
| `mode` | Não | `card`, `text`, `image` | `card` | Apresentação visual: um card autocontido com ícone e botão, um link de texto embutido, ou uma imagem clicável apenas com ícone. |
| `text` | Não | Qualquer texto | O nome do item | Rótulo customizado exibido junto ao gatilho (ignorado para itens secretos até serem coletados). |
| `button_text` | Não | Qualquer texto | *"Take"* | Sobrescreve o texto do botão de coleta (modos `card`/`text`). |
| `button_emoji` | Não | Qualquer emoji | 🖐 | Sobrescreve o emoji do botão de coleta (modo `card`). |

```
[PLAYERHUD_DROP code=XPTO123]
[PLAYERHUD_DROP code=XPTO123 mode=text text="Pegue a espada"]
[PLAYERHUD_DROP code=XPTO123 mode=image]
[PLAYERHUD_DROP code=XPTO123 button_text="Coletar!" button_emoji="⚔️"]
```

Um **item secreto** (marcado como tal no Painel de Gerenciamento) sempre renderiza como um
placeholder de mistério genérico — nome, descrição e XP ocultos — até que o estudante realmente
o colete, mesmo que um atributo `text` customizado seja fornecido.

Um item pode opcionalmente ser restrito a classes RPG específicas; um estudante fora das classes
permitidas nunca vê a saída do shortcode (nem mesmo um placeholder).

### `[PLAYERHUD_TRADE code=...]`

Renderiza um card de troca da loja NPC embutido no conteúdo, com verificação de saldo em tempo
real contra o inventário atual do usuário.

| Atributo | Obrigatório | Valores | Descrição |
|----------|:-----------:|---------|-----------|
| `code` | Sim | Código de 6 caracteres exibido na entrada da troca no Painel de Gerenciamento | Identifica a troca a ser renderizada. |

```
[PLAYERHUD_TRADE code=A1B2C3]
```

O `code` da troca é uma conveniência de consulta curta, não uma barreira de segurança — o
acesso à troca em si é sempre revalidado no servidor (sesskey, capability e checagem de grupo)
no momento em que o estudante efetivamente a realiza.

## Observações

* Múltiplos shortcodes na mesma página são todos resolvidos em uma única passagem de
  carregamento em lote — adicionar mais drops a uma página não adiciona consultas ao banco de
  dados proporcionalmente.
* Dentro do app móvel do Moodle, `[PLAYERHUD_DROP ...]` e `[PLAYERHUD_TRADE ...]` não
  renderizam nada (o fluxo de coleta via AJAX é exclusivo da web); os estudantes são
  direcionados para a própria visão de Mochila do bloco.
