# Symfony Git Installer

Ein leichtgewichtiges PHP-Tool zum Herunterladen und Extrahieren von Branches oder Tags aus GitHub-Repositories. Das Design und die Funktionsweise sind an Composer angelehnt.

Dieses Tool eignet sich besonders zum schnellen Deployment oder Update von Symfony-Anwendungen (oder anderen Projekten) direkt von GitHub auf einen Webserver, ohne dass Git auf dem Server installiert sein muss.

## Funktionen

- Herunterladen von Branches und Tags über die GitHub API.
- **Self-Update**: Der Installer kann sich selbst auf neuere Versionen von GitHub aktualisieren.
- **Umgebungsverwaltung**: Bearbeiten der `.env.local` direkt im Tool, Verwaltung von Datenbankverbindungen und `APP_ENV`.
- **Symfony-Integration**: Überprüfung des Doctrine-Migrationsstatus, Ausführen von Migrationen und Leeren des Caches.
- Authentifizierungsschutz per Passwort.
- Unterstützung für GitHub-Tokens (für private Repositories).
- Ausschlusslisten für Ordner und Dateien (z.B. `.git`, `node_modules`, `tests`).
- Whitelist für Dateien und Ordner, die beim Update nicht überschrieben werden sollen (z.B. `.env.local`).
- Automatische Bereinigung des Zielverzeichnisses vor der Installation.

## Voraussetzungen

- PHP 8.2 oder höher (empfohlen PHP 8.4).
- PHP-Erweiterungen: `curl`, `zip`, `openssl`.
- Schreibrechte im Zielverzeichnis.
- `shell_exec` aktiviert (optional, für Symfony-Konsolenbefehle wie Migrationen und Cache-Cleaning).

## Installation

1. Kopiere den Inhalt des `src`-Verzeichnisses in ein Verzeichnis auf deinem Webserver (z.B. `/pfad/zu/deinem/projekt/public/update`).
   *Hinweis: Der Ordnername `update` ist frei wählbar (z.B. `git-deploy`, `install` etc.).*
2. Benenne die `config.example.php` in `config.php` um und passe sie an.
3. Rufe das Verzeichnis in deinem Browser auf (z.B. `https://deine-domain.de/update`).

## Konfiguration (`config.php`)

Die Konfiguration erfolgt über ein PHP-Array in der Datei `src/config.php`. Hier sind die wichtigsten Optionen:

- `installer_version`: Die aktuell installierte Version des Installers. Wird automatisch beim Self-Update aktualisiert.
- `project_version`: Die aktuell installierte Version des Hauptprojekts (Branch oder Tag).
- `repository`: Das GitHub-Repository im Format `Benutzer/Repository`.
- `github_token`: Ein GitHub Personal Access Token (PAT) für den Zugriff auf private Repositories oder zur Erhöhung der API-Limits.
  *Beispiel:* `$_ENV['GITHUB_TOKEN']` kann verwendet werden, um das Token über Umgebungsvariablen bereitzustellen (z.B. in einer `.env`-Datei oder in der Webserver-Konfiguration).
- `password`: Ein optionales Passwort (Plaintext oder Hash), um den Zugriff auf den Installer zu schützen.
- `target_directory`: Das Verzeichnis, in das das Projekt installiert werden soll (relativ zum `src`-Verzeichnis).
- `updater_source_path`: Pfad innerhalb des Repositories, in dem sich der Installer-Quellcode befindet (Standard: `public/update`). Wird für Self-Updates verwendet.
- `show_versions_before_login`: Wenn auf `true` gesetzt, werden die aktuellen Versionen auf der Login-Seite angezeigt.
- `exclude_folders` / `exclude_files`: Listen von Ordnern und Dateien, die aus dem GitHub-Archiv **nicht** extrahiert werden sollen.
- `whitelist_folders` / `whitelist_files`: Listen von Ordnern und Dateien im Zielverzeichnis, die bei einer Neuinstallation **erhalten bleiben** sollen.
- `default_language`: Die Standardsprache für die Benutzeroberfläche (z.B. `en`, `de`, `fr`).
  Derzeit unterstützte Sprachen: `en`, `de`, `es`, `fr`, `it`, `ja`, `ko`, `nl`, `pl`, `pt`, `ru`, `zh`.
  Alle Sprachen im Verzeichnis `src/lang/` sind verfügbar.

## Sicherheitshinweise

- Es wird dringend empfohlen, ein Passwort in der `config.php` zu setzen.
- Wenn möglich, schütze das Verzeichnis zusätzlich mit einer `.htaccess`-Datei (Authentifizierung oder IP-Beschränkung).
- Lösche den Installer vom Server, sobald das Deployment abgeschlossen ist, wenn er nicht regelmäßig für Updates benötigt wird.

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert. Siehe `LICENSE` Datei für Details.
