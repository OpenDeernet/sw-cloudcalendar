# Betrieb der verifizierten GitHub-Updates

**Produktive Updateversorgung nicht bestätigt.** Der vom Betreiber eingetragene öffentliche Schlüssel `sw-cloudcalendar-2026-11` ist im Plugin verankert. Vor einer Updateversorgung müssen bestehende Installationen diesen Vertrauensanker separat erhalten; außerdem sind die geschützte GitHub-Umgebung und ein signierter Release erforderlich. Private Produktionsschlüssel oder GitHub-Secrets wurden bei der Implementierung nicht erzeugt oder geändert.

Quelle: [ChurchTools Suite @49dc66f](https://github.com/sw-wordpress/churchtools-suite/tree/49dc66f), insbesondere `includes/update/*`, Signierer und Tests; die Integration in die native Pluginzeile folgt sw-backupit. Es wird kein Referenzcode zur Laufzeit nachgeladen.

## Identität und Betrieb

- Repository `sw-wordpress/sw-cloudcalendar`, numerische ID **1435009521** (GitHub-API geprüft), einziges Plugin `sw-cloudcalendar/sw-cloudcalendar.php`, Version `SW_CALENDAR_VERSION`. ZIP: `sw-cloudcalendar-<version>.zip`, Hauptdatei `sw-cloudcalendar/sw-cloudcalendar.php`.
- Bedienung ausschließlich unter **Plugins → Installierte Plugins** (Multisite: Netzwerkverwaltung). Dort stehen Prüfschaltfläche, letzter Erfolg und Fehler. Installation ausschließlich durch native Einzel-, AJAX- und Sammelupdates. Die automatische Installation ist auch für alte Angebote gesperrt.
- Autorisierte interaktive Backend-Besuche prüfen nach dem Seitenaufbau höchstens alle 24 Stunden. Fehler verbrauchen den Versuch. Manuelle Prüfungen benötigen Nonce, `update_plugins`, erlaubte Dateimodifikationen und 60 Sekunden Abstand. Netzwerkadministratoren dürfen auch über `admin-ajax.php` prüfen, obwohl dort die Netzwerkmarkierung fehlt.
- Frontend, Cron, REST und WP-CLI rufen GitHub niemals ab. Darstellungen verwenden lokalen Cache. Ohne Backend-Besuch keine tägliche Prüfung. Angebote verfallen spätestens sieben Tage nach erfolgreicher Prüfung; eine ältere verifizierte Version darf den höchsten bekannten Stand nicht ersetzen.
- Nur lokal verankerte Ed25519-Schlüssel; keine unsignierten oder Hash-only-Fallbacks. Laufzeit benötigt PHP 8.2, Sodium und für Installationen ZipArchive. Ohne nötige Erweiterung bleibt der Updateweg gesperrt. Öffentliche Schlüssel liegen in `src/Infrastructure/WordPress/Update/Config/trusted-keys.php`, alternativ in der pluginspezifischen Array-Konstante `SW_CALENDAR_RELEASE_PUBLIC_KEYS`.
- Nicht autoloadende Optionen: `sw_calendar_verified_github_updates_v1`, `sw_calendar_verified_github_updates_lock_v1`; Migration `sw_calendar_verified_updates_migrated`. Multisite verwendet Netzwerkzustand. Cache-Löschung hebt Sperrfristen nicht auf. Lock-Lease: 120 Sekunden; atomare Übernahme und bedingte Freigabe, ohne MySQL/MariaDB `GET_LOCK` sicher gesperrt.
- Weitere eigene Namen: AJAX `sw_calendar_check_github_updates`, Nonce `sw_calendar_verified_updates`, Filter `sw_calendar_verified_release_public_keys` und `sw_calendar_verified_update_descriptors`, Skript `sw_calendar-verified-updates`, Global `sw_calendarVerifiedUpdates`, DOM `sw_calendar-verified-updates-*`. Eine globale SW-Vertrauensliste wird ignoriert.
- HTTPS, feste GitHub-Hosts und Repositorypfade, höchstens vier Redirects, 5 Sekunden pro Metadatenabruf (Manifest 256 KiB, Signatur 1 KiB), 60 Sekunden pro ZIP-Abruf, 50 MiB ZIP, 250 MiB entpackt, 10.000 Einträge, begrenztes Kompressionsverhältnis. Vor Core-Extraktion werden exakte Signaturbytes, Identität/Assets/Version/Anforderungen, Hash/Größe, Wurzelordner, Pfade/Dateitypen/Duplikate und Header erneut geprüft. Keine eigene Installation; private temporäre Dateien werden bei Fehlern und am Request-Ende gelöscht.
- Kein früherer Updater/Installer oder eigener Update-Cron im Zielplugin vorhanden. Migration entfernt nur eigene Angebote und Auto-Update-Auswahlen; Deaktivierung entfernt eigene Angebote vor Wegfall der Guards. Kalender-Cronjobs sind davon unabhängig.

## Copy & Paste: Schlüssel für SW CloudCalendar einrichten

Diese Befehle sind für Bash/Zsh auf dem lokalen Entwicklungs-/Release-Rechner mit PHP und Sodium gedacht, nicht für den produktiven WordPress-Webserver. Der Checkout liegt hier unter `/var/www/html/wordpress_dev/wp-content/plugins/sw-cloudcalendar`. Private Dateien landen ausschließlich unter `$HOME/.local/share/sw-cloudcalendar-release-keys`, außerhalb dieses Checkouts und Webroots. Diesen Ordner separat verschlüsselt sichern; er gehört nicht in Git, Release-ZIPs oder öffentlich synchronisierte Ordner. Die Befehle wurden mit Wegwerfschlüsseln geprüft; produktive Schlüssel werden nicht durch die Dokumentation angelegt.

**1. Einmalig Schlüssel erzeugen.** Der Block verweigert einen bereits vorhandenen Schlüsselordner, damit ein erneutes Einfügen keinen bestehenden Schlüssel ersetzt. Die Key-ID ist eine öffentliche Kennung; beim späteren Schlüsselwechsel eine neue Kennung und einen neuen Ordner verwenden.

```bash
(
  set -eu
  umask 077
  export SWC_RELEASE_KEYS_DIR="$HOME/.local/share/sw-cloudcalendar-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('PHP-Sodium fehlt.');
}
$dir = getenv('SWC_RELEASE_KEYS_DIR');
if (!$dir || file_exists($dir) || is_link($dir)) {
    throw new RuntimeException('Schlüsselordner existiert bereits oder Pfad fehlt. Keine Dateien verändert.');
}
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Schlüsselordner konnte nicht angelegt werden.');
}
$id = 'sw-cloudcalendar-2026-11';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$files = [
    'signing-key.base64' => base64_encode($secret) . "\n",
    'public-keys.php' => "<?php\nreturn " . var_export([$id => base64_encode($public)], true) . ";\n",
    'key-id.txt' => $id . "\n",
];
foreach ($files as $name => $contents) {
    $handle = fopen($dir . '/' . $name, 'x');
    if (!$handle || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Schlüsseldatei konnte nicht vollständig geschrieben werden.');
    }
    fclose($handle);
}
sodium_memzero($secret);
sodium_memzero($pair);
echo "Schlüsseldateien erzeugt; privater Schlüssel wurde nicht ausgegeben.\n";
PHP
)
```

**2. Nur den öffentlichen Schlüssel ins Plugin übernehmen.** Dieser Block erhält bereits vorhandene öffentliche Schlüssel und lehnt eine abweichende Belegung derselben Key-ID ab. Dafür werden keine privaten Schlüssel in eine Umgebungsvariable oder nach WordPress kopiert.

```bash
(
  set -eu
  export SWC_PLUGIN_DIR='/var/www/html/wordpress_dev/wp-content/plugins/sw-cloudcalendar'
  export SWC_RELEASE_KEYS_DIR="$HOME/.local/share/sw-cloudcalendar-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
$target = getenv('SWC_PLUGIN_DIR') . '/src/Infrastructure/WordPress/Update/Config/trusted-keys.php';
$existing = require $target;
$incoming = require getenv('SWC_RELEASE_KEYS_DIR') . '/public-keys.php';
if (!is_array($existing) || !is_array($incoming) || count($incoming) !== 1) {
    throw new RuntimeException('Ungültiger öffentlicher Schlüsselring.');
}
foreach ($incoming as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($decoded) || strlen($decoded) !== 32 || (isset($existing[$id]) && $existing[$id] !== $key)) {
        throw new RuntimeException('Ungültiger öffentlicher Schlüssel oder widersprüchliche Key-ID.');
    }
    $existing[$id] = $key;
}
$contents = "<?php\n/**\n * Public Ed25519 verification keys.\n *\n * @package sw-cloudcalendar\n */\n\nreturn array(\n";
$width = max(array_map('strlen', array_keys($existing)));
foreach ($existing as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) || !is_string($decoded) || strlen($decoded) !== 32) {
        throw new RuntimeException('Ungültiger vorhandener öffentlicher Schlüssel.');
    }
    $contents .= "\t" . str_pad("'" . $id . "'", $width + 2) . " => '" . $key . "',\n";
}
$contents .= ");\n";
if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('Öffentlicher Schlüsselring konnte nicht geschrieben werden.');
}
echo "Öffentlicher Schlüsselring aktualisiert.\n";
PHP
  php -l "$SWC_PLUGIN_DIR/src/Infrastructure/WordPress/Update/Config/trusted-keys.php"
  git -C "$SWC_PLUGIN_DIR" diff -- src/Infrastructure/WordPress/Update/Config/trusted-keys.php
)
```

Die angezeigte Änderung an `src/Infrastructure/WordPress/Update/Config/trusted-keys.php` enthält ausschließlich den öffentlichen Schlüssel. Diese Datei nach Review regulär committen. Bestehende Installationen benötigen sie einmal über einen vertrauenswürdigen manuellen Upload oder eine lokale Konfiguration; erst dann können sie signierte Folgeupdates akzeptieren.

**3. GitHub vorbereiten und Secret hinterlegen.** Zuerst in den [Repository-Environments](https://github.com/sw-wordpress/sw-cloudcalendar/settings/environments) die Umgebung `plugin-releases` anlegen und Freigaben sowie erlaubte Release-Tags passend zum Workflow absichern. Erst danach folgenden Block mit einem für dieses Repository berechtigten GitHub-CLI-Konto ausführen. `gh auth status` prüft die Anmeldung; falls nötig zuvor `gh auth login` ausführen.

In **Settings → Environments → plugin-releases** zuerst **Required reviewers** setzen, Selbstfreigabe verhindern und unter **Deployment branches and tags** ausschließlich Release-Tags (`v*` und `[0-9]*`), keine Branches, zulassen. Branch-/Tag-Schutz für Release-Code einrichten. GitHub kann eine fehlende Umgebung automatisch ungeschützt anlegen; der Workflow ersetzt diese vorherige Betreiberkonfiguration nicht.

```bash
(
  set -eu
  SWC_RELEASE_KEYS_DIR="$HOME/.local/share/sw-cloudcalendar-release-keys"
  gh auth status
  gh secret set SW_PLUGIN_RELEASE_SIGNING_KEY \
    --repo sw-wordpress/sw-cloudcalendar --env plugin-releases \
    < "$SWC_RELEASE_KEYS_DIR/signing-key.base64"
  gh variable set SW_PLUGIN_RELEASE_KEY_ID \
    --repo sw-wordpress/sw-cloudcalendar --env plugin-releases \
    --body "$(cat "$SWC_RELEASE_KEYS_DIR/key-id.txt")"
  gh secret list --repo sw-wordpress/sw-cloudcalendar --env plugin-releases
  gh variable list --repo sw-wordpress/sw-cloudcalendar --env plugin-releases
)
```

Der private Schlüssel wird über Standardeingabe an GitHub übergeben und erscheint weder im Befehlsargument noch in der Terminalausgabe. Die Listen zeigen Secret-Namen beziehungsweise öffentliche Variablen. Referenzen: [GitHub CLI: Secret setzen](https://cli.github.com/manual/gh_secret_set), [Variable setzen](https://cli.github.com/manual/gh_variable_set), [PHP: Ed25519-Schlüsselpaar erzeugen](https://www.php.net/manual/en/function.sodium-crypto-sign-keypair.php).

Ein erneutes Setzen des Secrets ersetzt den GitHub-Wert. Deshalb vor Ausführung sicherstellen, dass dieser lokale Schlüssel zum gewünschten öffentlichen Schlüsselring passt; bei Rotation den Ablauf weiter unten verwenden. Die Befehle erzeugen keinen Release-Tag und veröffentlichen kein ZIP. Der eingerichtete Tag-Workflow übernimmt später Build, Signierung und Veröffentlichung.

## Vertrauensstart, Rotation und Store-Wechsel

Der öffentliche Schlüssel muss **vor** dem ersten signierten Folgeupdate einmal über einen vertrauenswürdigen manuellen ZIP-Upload oder lokale Konfiguration verteilt werden. Der erste heruntergeladene Release kann seinen eigenen Schlüssel nicht legitimieren.

Rotation: mit neuer Key-ID und neuem privaten Ordner ein neues Paar erzeugen; öffentlichen Schlüssel zusätzlich übernehmen. Ein noch mit dem alten Schlüssel signiertes Übergangsrelease muss beide öffentlichen Schlüssel ausliefern. Erst nach dessen Installation das Environment-Secret und die Key-ID auf den neuen Schlüssel umstellen. Alten öffentlichen Schlüssel erst später entfernen. Bei kompromittiertem Schlüssel ist ein separat vertrauenswürdiger Wiederherstellungsweg nötig.

Store-Builds müssen den Aufruf `Update\Provider::init()`, die `src/Infrastructure/WordPress/Update/`-Komponenten, Lifecycle-Verweise, das Update-Skript und den `Update URI`-Header entfernen. Bestehende Installationen erhalten ein letztes signiertes Übergangsrelease, das gezielt eigene Angebote/Optionen bereinigt und zum WordPress.org-Provider wechselt. Kein automatischer Wechsel anhand eines gleichnamigen Store-Eintrags.

## Release und Validierung

Nach Einrichtung der Schlüssel und GitHub-Schutzregeln:

```sh
make release VERSION=1.2.3
```

Wie bei sw-backupit aktualisiert der Befehl den Plugin-Header und `SW_CALENDAR_VERSION`, committet die Versionsänderung, erzeugt einen annotierten Tag und pusht Branch und Tag atomar nach `origin`. Voraussetzung: sauberer Arbeitsbaum einschließlich ungetrackter Dateien, ausgecheckter Branch und lokal wie remote noch freier Tag. Fehlende/ungültige Versionen, Downgrades, widersprüchliche Versionen und fehlgeschlagene Remote-Prüfungen brechen ab. Auch vierteilige Versionen (`VERSION=1.2.3.4`) sind zulässig. Eine gleiche Version darf getaggt werden, sofern der Tag noch frei ist; dafür entsteht kein zusätzlicher Versionscommit.

Ein abgelehnter Push bleibt als Fehler sichtbar; lokaler Versionscommit und Tag bleiben zur Prüfung erhalten. Der Befehl führt keinen Force-Push aus und erstellt keine Schlüssel oder Secrets. Der Tag startet den signierten Release-Workflow; das allein bestätigt noch keine erfolgreiche Veröffentlichung.

Der Tag muss `[v]X.Y.Z[.W][-NN]` entsprechen; die normalisierte Version muss Header und `SW_CALENDAR_VERSION` entsprechen. `.github/workflows/main.yml` verwendet `plugin-releases`, ergänzt den bestehenden öffentlichen Schlüsselring um den Build-Schlüssel, baut ZIPs, lädt sie in einen Draft, signiert tatsächliche Asset-IDs und veröffentlicht erst den vollständigen Satz. Fehlende Schlüssel brechen ab; veröffentlichte Releases werden nicht überschrieben. Alte Tags verwenden ihren damaligen Workflow und benötigen bei Korrekturen einen neuen Tag.

Testsuiten laufen ausschließlich auf Pull Requests (`tests.yml`); der Release-Workflow validiert und signiert Artefakte ohne Testsuite. DB-Suiten mit gemeinsamem Zustand laufen sequenziell. Lokal: `composer check`, `composer test:wp`, `WP_TESTS_MULTISITE=1 composer test:wp -- --filter VerifiedUpdatesTest`, `bash tests/E2E/with-browser.sh node apps/admin/scripts/test-updates-ui.mjs`, `bash bin/build-zip.sh dist`, `php tests/release/verify-signed-build.php dist`. Weitere Schritte und Ergebnisse: [Verifikation](GITHUB-UPDATES-VERIFIKATION.md), [bestehende Testanleitung](testing.md).

Grenzen: Kein Schutz gegen kompromittierten lokalen PHP-Code oder einen kompromittierten Signierprozess. Ohne signiertes zeitlich gültiges Channel-Verzeichnis können erstmalige Clients vorenthaltene neuere Releases nicht sicher erkennen. Änderungen der GitHub-CDN-Hosts können eine Clientanpassung erfordern. Keine privaten Repositorys oder GitHub-Tokens in WordPress. GitHub-Umgebung, Schutzregeln und echte Veröffentlichung sind Betreiberaufgaben und werden durch lokale Tests nicht bestätigt.
