# Moodle Filter PlayerHUD

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Stable-green?style=flat-square)

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

### 🔗 Part of the PlayerHUD Ecosystem

This plugin works together with:

* **PlayerHUD Block (Required)**  
  👉 https://github.com/jeanlucio/moodle-block_playerhud

Optional extension:

* **PlayerHUD Availability Condition**  
  👉 https://github.com/jeanlucio/moodle-availability_playerhud

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

### 🔗 Parte do Ecossistema PlayerHUD

Este plugin funciona em conjunto com:

* **Bloco PlayerHUD (Obrigatório)**  
  👉 https://github.com/jeanlucio/moodle-block_playerhud

Extensão opcional:

* **Restrição de Acesso PlayerHUD**  
  👉 https://github.com/jeanlucio/moodle-availability_playerhud

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

## 📄 License / Licença

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio
