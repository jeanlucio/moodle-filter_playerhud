# 🧪 Testes Automatizados

O filtro inclui testes unitários/integração (PHPUnit) e de aceitação em navegador (Behat),
executados a cada push de CI contra a matriz completa (Moodle 4.5 → 5.2, PostgreSQL & MariaDB).

### PHPUnit — Testes Unitários e de Integração

| Arquivo de teste | Casos |
|-------------------|------:|
| `filter_test.php` | 28 |
| **Total** | **28** |

```bash
vendor/bin/phpunit --testsuite filter_playerhud
```

**Cobertura de linhas total** (PHPUnit + Xdebug): **79%**.

### Behat — Testes de Aceitação

| Arquivo de feature | Cenários |
|--------------------|--------:|
| `filter_playerhud_modals.feature` | 6 |
| **Total** | **6** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@filter_playerhud --profile=chrome
```

[Detalhamento completo dos testes e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})
