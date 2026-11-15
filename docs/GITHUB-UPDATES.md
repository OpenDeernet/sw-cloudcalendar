# Verifizierte manuelle GitHub-Updates

CloudCalendar verwendet eine lokale Adaption des signierten Providers aus [ChurchTools Suite @49dc66f](https://github.com/sw-wordpress/churchtools-suite/tree/49dc66f), mit der Pluginverwaltungs-UI von sw-backupit als Vorbild.

Die Komponenten unter `src/Infrastructure/WordPress/Update/` trennen lokale Identität (`Config/`), Signatur und Schema (`Manifest`), begrenztes HTTPS (`Transport`), ZIP-Prüfung (`Package`), persistenten Netzwerkzustand und Lock (`Store`), Prüfpolitik (`Service`) und native WordPress-Hooks (`Provider`). Gemeinsame Hooks lassen fremde Plugins unverändert; öffentliche Schlüssel und Zustand sind pluginspezifisch. Der Provider installiert nicht selbst.

Alle Betriebsregeln, konkreten Schlüsselbefehle, Release-Abläufe und der Store-Wechsel stehen im [Betriebshandbuch](GITHUB-UPDATES-BETRIEB.md). Prüfungen und verbleibende Grenzen stehen im [Verifikationsbericht](GITHUB-UPDATES-VERIFIKATION.md).
