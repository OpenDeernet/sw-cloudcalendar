# Verifikation der GitHub-Updates

Stand: 15. November 2026. Lokale Prüfung mit PHP 8.3, PHPStan Level 8 und isolierter MariaDB/WordPress-Testinstallation. Keine privaten Produktionsschlüssel, Secrets oder Releases verändert. Der vom Betreiber hinzugefügte öffentliche Schlüssel `sw-cloudcalendar-2026-11` wird mit ausgeliefert; eine produktiv aktive Updateversorgung wird damit nicht bestätigt.

## Änderungen

Eigener Provider für `sw-wordpress/sw-cloudcalendar` (GitHub-ID `1435009521`), mit Bedienung ausschließlich in der nativen Pluginübersicht. Signatur- und Paketprüfer, persistente Sperrfrist, Netzwerk-Lock, manuelle Core-Installation und gezielte Deaktivierungsbereinigung sind integriert. Die vorhandene Laufzeitkonstante `1.0.0` wurde an den tatsächlichen Plugin-Header `0.7.0` angeglichen; der Build verweigert widersprüchliche Versionen. Ein relativer ZIP-Ausgabeordner wird vor dem Verpacken absolut aufgelöst.

Der Release-Workflow verwendet `plugin-releases`, ergänzt öffentliche Schlüssel, signiert tatsächliche Draft-Asset-IDs und verifiziert zurückgeladene Artefakte vor Veröffentlichung. PR-Tests und Release-Build sind getrennt. Referenz und Architektur: [GITHUB-UPDATES.md](GITHUB-UPDATES.md).

## Automatisierte Ergebnisse

| Prüfung | Ergebnis |
| --- | --- |
| PHP Unit/Integration | 196 Tests, 545 Assertions bestanden |
| Vollständige WordPress-Suite mit Coverage | 280 Tests, 1.555 Assertions bestanden |
| Update-Suite, Single-Site | 106 Tests, 359 Assertions bestanden |
| Update-Suite, Multisite | 106 Tests, 370 Assertions bestanden |
| Zusammengeführte PHP-Zeilenabdeckung | **89,69 %** (7.206 / 8.034 Zeilen, 203 Dateien) |
| JavaScript/TypeScript | 191 Tests in 22 Dateien bestanden; **83,55 %** Zeilenabdeckung |
| Update-Skript im Docker-Browser | **100 %** ausführbare Zeilen (33 / 33); manuelle/fällige Prüfung, Single-Flight, Fehler, Timeout, fehlende Konfiguration, Timer-Fallback |
| Native WordPress-E2E mit Docker-Chromium | 30 Prüfungen bestanden; Pluginzeile, fehlender Schlüssel, manuelle Prüfung ohne Navigation/Installation, Automatik aus sowie bestehende Kalender-/Editorabläufe |
| PHPStan Level 8 | Keine Fehler; gesamter konfigurierter produktiver PHP-Code einschließlich neuer Komponenten und Build-CLI, keine Baseline/Ignores |
| WordPress Coding Standards / Compatibility | Keine Fehler oder Warnungen |
| PSR-12 / Compatibility | Keine Fehler; bestehende Warnungen zu langen Zeilen und zum vorhandenen Bootstrap-Test bleiben unverändert; keine neuen Warnungen in den ergänzten Dateien |
| ESLint / TypeScript | Bestanden |
| Übersetzungen | POT, deutsches PO/MO synchron; `bin/i18n.sh check` bestanden |
| Composer-Konfiguration / Workflow-Syntax | `composer validate --strict` und Actionlint bestanden |
| Echter Release-Build | `dist/sw-cloudcalendar-0.7.0.zip`, einschließlich isolierter Laufzeitabhängigkeiten, Admin-Bundle, Prüfern und öffentlichem Schlüsselring |
| Signierter echter Build | Wegwerfschlüssel, tatsächliche ZIP-Bytes, Laufzeitverifikation, Paketvollständigkeit, gleicher Schlüsselring; fehlendes Signier-Secret verweigert; falscher Schlüssel und manipuliertes Download-Artefakt verweigert |
| Dokumentierte Schlüsselbefehle | Alle drei Blöcke unter Bash und Zsh isoliert geprüft; `0700/0600`, Überschreibschutz, Erhalt alter öffentlicher Schlüssel, Key-ID-Konflikte, private Übergabe ausschließlich über stdin an simuliertes `gh` |
| Separate CLI-/REST-Prozesse | Prüfung und Installation trotz ansonsten gewährter Backend-Berechtigungen gesperrt; kein Zustand/HTTP-Zugriff |
| Fehlende Erweiterungen | Separater PHP-Prozess ohne ZipArchive und mit deaktiviertem Sodium-Verifier verweigert Manifest und Paket |

`VerifiedUpdatesTest` prüft gültige/ungültige Signaturen und exakte Bytes, falsche Identitäten/Assets und uneindeutige Asset-Namen/-IDs, Schema und doppelte JSON-Schlüssel, stabile Releases und Anforderungen, manipulierte ZIPs, Pfad-/Dateityp-/Duplikat-/Symlink-/Entpackgrenzen und Datei-/Verzeichniskollisionen. Dazu kommen Redirect-/Downloadlimits, Nonces und Berechtigungen, Frontend/Cron, persistente Sperrfrist auch nach Fehler und Transient-Löschung, Rollback, Lock-Übernahme/Eigentümerschutz, Deaktivierung und Multisite-AJAX ohne Netzwerkmarkierung.

Echte native Einzel-, AJAX- und Sammelupgrades schreiben ausschließlich in das private WordPress unter `tests/wp/vendor/`; ein Pfad-Assertion verhindert Änderungen an den produktiven Plugin-Dateien. Eine zweite, unabhängig aktivierte Providerkopie im privaten Pluginverzeichnis prüft gleichzeitige Hooks, getrennte Vertrauenslisten, Optionen, Locks und AJAX-/Nonce-Namen. Das ist ein kontrollierter Kollisionstest; nicht sämtliche veröffentlichten Versionen anderer SW-Plugins sind damit getestet.

Docker-Browseraufrufe:

```bash
bash tests/E2E/with-browser.sh node apps/admin/scripts/test-updates-ui.mjs
bash tests/E2E/with-browser.sh tests/E2E/run.sh /tmp/swc-updates-e2e
```

Die Browser-E2E legt Screenshots und das Ergebnisprotokoll im angegebenen Arbeitsordner ab. Die Testdatenbanken sind von der Entwicklungs-/Produktivdatenbank getrennt; Suiten mit gemeinsamem Zustand laufen sequenziell.

## Konkrete manuelle Abnahme

1. Das gebaute ZIP in einer separaten WordPress-Testinstallation manuell hochladen und aktivieren. Unter **Plugins → Installierte Plugins** müssen in der CloudCalendar-Zeile die Prüfschaltfläche und der lokale Prüfstatus erscheinen. Für die gesonderte Missing-Key-Abnahme ausschließlich in dieser Testinstallation `SW_CALENDAR_RELEASE_PUBLIC_KEYS` als leeres Array setzen. Es gibt keine zusätzliche Kalender-Einstellungsseite für Updates.
2. **Jetzt auf Updates prüfen** in der Missing-Key-Testinstallation klicken. Erwartet: ausdrückliche Sperrmeldung wegen fehlendem Schlüssel; kein Installationsstart und keine Navigation. Die Auto-Update-Spalte zeigt ausschließlich manuelle Aktualisierung. Als Benutzer ohne `update_plugins` darf keine Prüfschaltfläche erscheinen.
3. Für die spätere signierte Abnahme einen ausschließlich für die Testquelle vorgesehenen Vertrauensanker manuell verteilen und einen vollständigen signierten Draft kontrollieren. Nach Veröffentlichung müssen native Einzel-/AJAX-/Sammelupdates funktionieren; automatische Installation bleibt gesperrt. Ungültige Signaturen und abgelaufene Angebote müssen vor Core-Extraktion abbrechen.
4. Im Netzwerkbackend wiederholen. Ein Site-Administrator ohne Netzwerkrechte darf weder prüfen noch installieren. Nach Deaktivierung dürfen eigene gecachte Angebote verschwunden sein; Angebote anderer Plugins müssen erhalten bleiben.

## Verbleibende Grenzen und Betreiberaufgaben

Die geschützte GitHub-Umgebung, Required reviewers, Branch-/Tag-Schutz, zugehöriger privater Signierschlüssel und ein echter signierter Release sind **nicht bestätigt**. Der öffentliche Schlüssel ist lokal konfiguriert; die vertrauenswürdige Erstverteilung an bestehende Installationen ist separat erforderlich. SonarQube Cloud, GitHub-Actions-Ausführung und eine Veröffentlichung mit echtem GitHub wurden lokal nicht ausgeführt. Der Build meldet vorhandene moderate npm-Audit-Funde in den bestehenden Abhängigkeiten; diese Änderung aktualisiert keine Abhängigkeiten.

Ein Update kann nur vor der Core-Installation vollständig geprüft werden; Dateisystemfehler während der nativen Installation bleiben WordPress-Fehler. Ohne MySQL/MariaDB `GET_LOCK` wird die Prüfung sicher gesperrt. Ein erstmaliger Client erkennt ohne separates signiertes Channel-Verzeichnis keine absichtlich vorenthaltene neuere Version. Bereits kompromittierter lokaler PHP-Code und kompromittierte Signierprozesse liegen außerhalb dieses Vertrauensmodells.

Betriebsbefehle, Schlüsselrotation und Store-Wechsel: [Betriebshandbuch](GITHUB-UPDATES-BETRIEB.md).

## Release-Befehl

`make release VERSION=1.2.3` folgt sw-backupit. Zwölf automatisierte Tests mit ausschließlich lokalen Wegwerf-Repositories prüfen Version/Commit/annotierten Tag/atomaren Push sowie ungültige Versionen, Downgrades, unsauberen Zustand, belegte Tags, Detached HEAD, fehlendes Remote und abgelehnte Pushes. Bash-Syntax, ShellCheck und Actionlint sind geprüft. Es wurde kein produktiver Release-Befehl ausgeführt.
