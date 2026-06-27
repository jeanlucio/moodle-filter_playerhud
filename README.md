# Moodle Filter PlayerHUD

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-filter_playerhud/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-filter_playerhud/actions/workflows/ci.yml)
![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat-square)
[![PlayerGames Ecosystem](https://img.shields.io/badge/PlayerGames-Ecosystem-6f42c1?style=flat-square&logo=gamepad&logoColor=white)](https://moodle.org/plugins/browse.php?list=contributor&id=3970322)
![Role](https://img.shields.io/badge/Role-Content_Integration-0d6efd?style=flat-square)

[English](#english) | [Português](#português)

---

## English

The **PlayerHUD Filter** is a required companion plugin for the PlayerHUD Block. It enables the insertion of collectible item drops directly inside Moodle course content using shortcodes.

This filter allows teachers to embed interactive drop elements within pages, labels, books, and other HTML-supported activities, integrating seamlessly with the PlayerHUD gamification system.

---

### ✨ Features

* 📍 Insert item drops directly into course content
* 🧩 Shortcode-based integration
* ⚡ Real-time interaction via Moodle `core/ajax`
* 🎒 Seamless integration with the PlayerHUD inventory system
* 🔐 Server-side validation of recharge time and collection limits
* 📱 Mobile-compatible rendering

---

### 🕹️ Part of the PlayerGames Ecosystem

This plugin is part of the **PlayerGames** gamification ecosystem. Together, these plugins transform Moodle into an immersive experience:

* **PlayerHUD Block (Required)**
  👉 https://github.com/jeanlucio/moodle-block_playerhud

* **PlayerHUD Availability Restriction**
  👉 https://github.com/jeanlucio/moodle-availability_playerhud

* **PlayerGroup:** Lets students autonomously form their own groups directly from the activity page — no teacher intervention needed.
  👉 https://github.com/jeanlucio/moodle-mod_playergroup

---

### 📦 Requirements

* **Moodle:** 4.5 or higher
* **Required Dependency:** PlayerHUD Block
  https://github.com/jeanlucio/moodle-block_playerhud
* **PHP:** Compatible with your Moodle version

---

### 🛠️ Installation

1. Ensure the **PlayerHUD Block** is installed first:
   👉 https://github.com/jeanlucio/moodle-block_playerhud
   The filter depends on the block and will not install without it.

2. Download the `.zip` file or clone this repository.
3. Extract the folder into your Moodle `filter/` directory.
4. Rename the folder to `playerhud` (if necessary).
   Final path:
   `your-moodle/filter/playerhud/`
5. Visit **Site administration > Notifications** to complete installation.
6. Enable the filter in:
   **Site administration > Plugins > Filters > Manage filters**

---

### 📖 Usage

1. Ensure the PlayerHUD Block is properly configured in the course.
2. Enable the **PlayerHUD Filter**.
3. Insert the PlayerHUD shortcode inside supported content areas.
4. Item drops will render dynamically within the course.
5. Students can collect items according to the rules defined in the PlayerHUD Block.

---

### 🔐 Security & Compliance

* Capability-based validation
* Server-side enforcement of recharge time and limits
* Secure AJAX interaction via Moodle External API
* Compatible with Moodle mobile services

---

### 🧪 Automated Tests

The filter ships with unit/integration (PHPUnit) and browser acceptance (Behat) tests, executed on every CI push.

#### PHPUnit — Unit & Integration Tests

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `filter_test.php` | 15 | Shortcode parsing · zero N+1 queries on multiple drops · drop visibility when gamification is paused · strips all shortcodes for guests and missing block · secret item mystery rendering · limit-reached and cooldown states · trade rendering by secure code (valid + invalid) · widget opt-in when paused · active HUD widget · Moodle app redirect · assets modals HTML · privacy null provider |
| **Total** | **15** | |

**Line coverage by class (PHPUnit + Xdebug):**

| Class | Line coverage |
|-------|:-------------:|
| `output\assets` | 100% |
| `privacy\provider` | 100% |
| `text_filter` | 85% |
| `output\render` | 71% |
| `output\widget` | 74% |
| **Overall** | **75%** |

```bash
vendor/bin/phpunit --testsuite filter_playerhud
```

#### Behat — Acceptance Tests

| Feature file | Scenarios | What is covered |
|--------------|----------:|----------------|
| `filter_playerhud_modals.feature` | 5 | AJAX collect without redirect, item detail from widget stash, HTML rendering in modal, no raw placeholders, duplicate-modal guard |
| **Total** | **5** | |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@filter_playerhud --profile=chrome
```

---

## 📄 License / Licença

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

---

## Português

O **Filtro PlayerHUD** é um plugin complementar obrigatório do Bloco PlayerHUD. Ele permite inserir drops de itens colecionáveis diretamente no conteúdo do curso Moodle por meio de shortcodes.

Esse filtro possibilita que o professor incorpore elementos interativos dentro de páginas, rótulos, livros e outras atividades que suportem HTML, integrando-se ao sistema de gamificação PlayerHUD.

---

### ✨ Funcionalidades

* 📍 Inserção de drops diretamente no conteúdo do curso
* 🧩 Integração baseada em shortcode
* ⚡ Interação em tempo real via `core/ajax`
* 🎒 Integração com o sistema de inventário do PlayerHUD
* 🔐 Validação no servidor do tempo de recarga e limites de coleta
* 📱 Compatível com dispositivos móveis

---

### 🕹️ Parte do Ecossistema PlayerGames

Este plugin faz parte do ecossistema de gamificação **PlayerGames**. Juntos, esses plugins transformam o Moodle em uma experiência imersiva:

* **Bloco PlayerHUD (Obrigatório)**
  👉 https://github.com/jeanlucio/moodle-block_playerhud

* **Restrição de Acesso PlayerHUD**
  👉 https://github.com/jeanlucio/moodle-availability_playerhud

* **PlayerGroup:** Permite que os alunos formem seus próprios grupos de forma autônoma diretamente na página da atividade — sem necessidade de intervenção do professor.
  👉 https://github.com/jeanlucio/moodle-mod_playergroup

---

### 📦 Requisitos

* **Moodle:** 4.5 ou superior
* **Dependência Obrigatória:** Bloco PlayerHUD
  https://github.com/jeanlucio/moodle-block_playerhud
* **PHP:** Compatível com a versão do Moodle

---

### 🛠️ Instalação

1. Certifique-se de que o **Bloco PlayerHUD** esteja instalado primeiro:
   👉 https://github.com/jeanlucio/moodle-block_playerhud
   O filtro depende do bloco e não será instalado sem ele.

2. Baixe o arquivo `.zip` ou clone o repositório.
3. Extraia a pasta para o diretório `filter/` do seu Moodle.
4. Renomeie para `playerhud` (se necessário).
   Caminho final:
   `seu-moodle/filter/playerhud/`
5. Acesse **Administração do site > Notificações** para concluir a instalação.
6. Ative o filtro em:
   **Administração do site > Plugins > Filtros > Gerenciar filtros**

---

### 📖 Como Usar

1. Certifique-se de que o Bloco PlayerHUD esteja configurado no curso.
2. Ative o **Filtro PlayerHUD**.
3. Insira o shortcode do PlayerHUD no conteúdo desejado.
4. Os drops serão renderizados dinamicamente dentro do curso.
5. Os alunos poderão coletar os itens conforme as regras definidas no Bloco PlayerHUD.

---

### 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities
* Validação no servidor do tempo de recarga e limites de coleta
* Interação segura via API externa do Moodle
* Compatível com serviços mobile do Moodle

---

### 🧪 Testes Automatizados

O filtro inclui testes unitários/integração (PHPUnit) e de aceitação em navegador (Behat), executados a cada push de CI.

#### PHPUnit — Testes Unitários e de Integração

| Arquivo de teste | Casos | O que é coberto |
|-----------------|------:|----------------|
| `filter_test.php` | 15 | Parsing de shortcode · zero queries N+1 com múltiplos drops · visibilidade de drops com gamificação pausada · remoção de shortcodes para convidados e curso sem bloco · renderização de item secreto como mistério · estados de limite atingido e cooldown · renderização de trade por código seguro (válido + inválido) · widget com opt-in pausado · widget HUD ativo · redirect no app Moodle · HTML dos modais de assets · null provider de privacidade |
| **Total** | **15** | |

**Cobertura de linhas por classe (PHPUnit + Xdebug):**

| Classe | Cobertura de linhas |
|--------|:-------------------:|
| `output\assets` | 100% |
| `privacy\provider` | 100% |
| `text_filter` | 85% |
| `output\render` | 71% |
| `output\widget` | 74% |
| **Total** | **75%** |

```bash
vendor/bin/phpunit --testsuite filter_playerhud
```

#### Behat — Testes de Aceitação

| Arquivo de feature | Cenários | O que é coberto |
|-------------------|--------:|----------------|
| `filter_playerhud_modals.feature` | 5 | Coleta AJAX sem redirecionamento, detalhe do item via widget stash, renderização HTML no modal, sem placeholders brutos, proteção contra modal duplicado |
| **Total** | **5** | |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@filter_playerhud --profile=chrome
```

---

## 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio
