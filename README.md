# ProMarkah

[![License](https://img.shields.io/github/license/MalGamerz/ProMarkah?cacheSeconds=60)](https://github.com/MalGamerz/ProMarkah)
[![Last Commit](https://img.shields.io/github/last-commit/MalGamerz/ProMarkah?cacheSeconds=60)](https://github.com/MalGamerz/ProMarkah/commits/main)
[![Repo Size](https://img.shields.io/github/repo-size/MalGamerz/ProMarkah?cacheSeconds=60)](https://github.com/MalGamerz/ProMarkah)
[![Top Language](https://img.shields.io/github/languages/top/MalGamerz/ProMarkah?cacheSeconds=60)](https://github.com/MalGamerz/ProMarkah)
[![Open Issues](https://img.shields.io/github/issues/MalGamerz/ProMarkah?cacheSeconds=60)](https://github.com/MalGamerz/ProMarkah/issues)
[![Stars](https://img.shields.io/github/stars/MalGamerz/ProMarkah?style=social&cacheSeconds=60)](https://github.com/MalGamerz/ProMarkah)

Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Quickstart](#quickstart)
- [Configuration](#configuration)
- [File structure](#file-structure)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)
- [Maintainer](#maintainer)

## Overview

ProMarkah is a scoring and attendance management system for silat competitions. This repository contains the application code only; all sensitive files were removed during import. The repository is ready for review and deployment once secrets are provisioned on the target server.

## Features

- Judge scoring workflow with draft autosaves and locking
- Public QR-based attendance (anonymous scanning)
- PIC (person-in-charge) admin pages for competition setup and results
- Export functionality (CSV, PDF)
- Minimal dependencies: PHP, vanilla JS, CSS

## Requirements

- PHP 7.4+ with mysqli
- Composer (optional for dependency installation)
- A web server (Apache, Nginx) or PHP built-in server for local testing
- MySQL/MariaDB for production use

## Quickstart

Clone and run locally:

```bash
git clone https://github.com/MalGamerz/ProMarkah.git
cd ProMarkah
composer install
# Provision secrets as described below
php -S localhost:8000 -t public_html
```

Open http://localhost:8000 to test the public pages.

## Configuration

Secrets must never be committed. Provision them outside the web root:

1. Copy `secrets.sample.php` to a path outside the web root (example: `../secrets.php`).
2. Edit `../secrets.php` with production credentials (DB host/user/password, QR HMAC key, Telegram bot token, etc.).
3. Ensure the secrets file is readable only by the web server user (chmod 600).
4. Confirm `security_bootstrap.php` can load `bootstrap_secrets.php` which references the external secrets file.

## File structure

Top-level load-bearing PHP filenames (do not rename):

- `index.php`, `judge.php`, `attendance.php`, `save_attendance.php`, `login.php`, `admin.php`, `pic_*.php`, etc.

Core support files:

- `db.php` — database connection and `getDB()`
- `security_bootstrap.php`, `bootstrap_secrets.php`, `bootstrap_error_handling.php` — error handling and secret loading
- `layout.php`, `layout.js`, `layout_icons.php` — shared UI and client helpers

Static assets live under `img/`, CSS/JS files are next to their pages or in top-level asset files.

## Security

- No credentials or private keys are stored in this repository.
- Do not commit any files that contain passwords, private keys, tokens, or other secrets.
- On production, disable `display_errors` and log to a secure file outside the web root.
- Verify file permissions: secrets should be readable only by the web server user.
- If any secret is discovered in the repository, rotate credentials immediately and contact the maintainer.

## Contributing

- Open issues for bugs, security reports, or enhancement requests.
- Create pull requests against `main` for code changes.
- Do not include secrets in commits. Use `secrets.sample.php` as a non-sensitive template.

## License

This project is unlicensed. Do not reuse, redistribute, or modify the code without explicit permission from the maintainer.

## Maintainer

MalGamerz — contact via the GitHub account: https://github.com/MalGamerz

