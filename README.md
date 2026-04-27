# Symfony Git Installer

A lightweight PHP tool for downloading and extracting branches or tags from GitHub repositories. The design and operation are inspired by Composer.

This tool is particularly suitable for quickly deploying or updating Symfony applications (or other projects) directly from GitHub to a web server without requiring Git to be installed on the server.

## Features

- Download branches and tags via the GitHub API.
- **Self-update**: The installer can update itself to newer versions from GitHub.
- **Environment Management**: Edit `.env.local` directly, manage database connections and `APP_ENV`.
- **Symfony Integration**: Check Doctrine migration status, run migrations, and clear cache.
- Authentication protection with a password.
- Support for GitHub tokens (for private repositories).
- Exclusion lists for folders and files (e.g., `.git`, `node_modules`, `tests`).
- Whitelist for files and folders that should not be overwritten during an update (e.g., `.env.local`).
- Automatic cleanup of the target directory before installation.

## Requirements

- PHP 8.2 or higher (PHP 8.4 recommended).
- PHP extensions: `curl`, `zip`, `openssl`.
- Write permissions in the target directory.
- `shell_exec` enabled (optional, for Symfony console commands like migrations and cache clearing).

## Installation

1. Copy the contents of the `src` directory to a directory on your web server (e.g., `/path/to/your/project/public/update`).
   *Note: The folder name `update` is freely selectable (e.g., `git-deploy`, `install`, etc.).*
2. Rename `config.example.php` to `config.php` and customize it.
3. Access the directory in your browser (e.g., `https://your-domain.com/update`).

## Configuration (`config.php`)

Configuration is done via a PHP array in the `src/config.php` file. Here are the most important options:

- `installer_version`: The currently installed version of the installer. Automatically updated during self-updates.
- `project_version`: The currently installed version of the main project (branch or tag).
- `repository`: The GitHub repository in `User/Repository` format.
- `github_token`: A GitHub Personal Access Token (PAT) for accessing private repositories or increasing API limits.
  *Example:* `$_ENV['GITHUB_TOKEN']` can be used to provide the token via environment variables (e.g., in a `.env` file or web server configuration).
- `password`: An optional password (plaintext or hash) to protect access to the installer.
- `target_directory`: The directory where the project should be installed (relative to the `src` directory).
- `updater_source_path`: Path within the repository where the installer source is located (default: `public/update`). Used for self-updates.
- `show_versions_before_login`: If `true`, current versions are displayed on the login page.
- `exclude_folders` / `exclude_files`: Lists of folders and files that should **not** be extracted from the GitHub archive.
- `whitelist_folders` / `whitelist_files`: Lists of folders and files in the target directory that should **be preserved** during a new installation.
- `default_language`: The default language for the user interface (e.g., `en`, `de`, `fr`).
  Currently supported languages: `en`, `de`, `es`, `fr`, `it`, `ja`, `ko`, `nl`, `pl`, `pt`, `ru`, `zh`.
  All languages in the `src/lang/` directory are available.

## Security Notes

- It is highly recommended to set a password in `config.php`.
- If possible, additionally protect the directory with a `.htaccess` file (authentication or IP restriction).
- Delete the installer from the server once the deployment is complete if it is not needed regularly for updates.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.
