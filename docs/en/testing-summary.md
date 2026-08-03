# 🧪 Automated Tests

The filter ships with unit/integration (PHPUnit) and browser acceptance (Behat) tests, executed
on every CI push against the full Moodle 4.5 → 5.2 matrix (PostgreSQL & MariaDB).

### PHPUnit — Unit & Integration Tests

| Test file | Cases |
|-----------|------:|
| `filter_test.php` | 22 |
| **Total** | **22** |

```bash
vendor/bin/phpunit --testsuite filter_playerhud
```

**Overall line coverage** (PHPUnit + Xdebug): **80%**.

### Behat — Acceptance Tests

| Feature file | Scenarios |
|--------------|----------:|
| `filter_playerhud_modals.feature` | 6 |
| **Total** | **6** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@filter_playerhud --profile=chrome
```

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
