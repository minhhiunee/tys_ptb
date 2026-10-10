# Photobooth System (tys_ptb)

A powerful and customizable Photobooth web interface designed for stability, high performance, and ease of deployment.

## 🚀 Technology Stack

This project is built using a modern, hybrid tech stack combining a robust PHP backend with a dynamic, responsive frontend.

### Backend
- **Core:** PHP 8.4
- **Architecture:** Object-Oriented PHP (OOP) with PSR-4 autoloading
- **Dependency Management:** Composer
- **Key Libraries:** 
  - `symfony/*` components (Console, Finder, Filesystem, Translation)
  - `league/flysystem` (Filesystem abstraction)
  - `monolog` (Logging)
  - `endroid/qr-code` (QR Code generation)

### Frontend
- **Logic & Interactions:** JavaScript (ES6+), jQuery, Socket.io
- **Styling:** SCSS, TailwindCSS, PostCSS
- **UI Components:** PhotoSwipe (Gallery), FontAwesome
- **Build Tools:** Node.js (>= v20.15), Gulp (Asset compilation, Minification, Pipeline)

### Deployment & Environment
- **Containerization:** Docker & Docker Compose
- **Base Image:** `webdevops/php-apache:8.4`
- **Supported OS:** Linux, Windows (via Docker or local web server environments)

---

## 📂 Project Structure

The codebase is organized into a modular structure to separate core logic, APIs, and frontend assets:

```text
├── admin/          # Admin panel interface
├── api/            # Backend API endpoints handling core operations (capture, print, config)
├── assets/         # Raw frontend source files (SCSS, JS) - processed by Gulp
├── config/         # System configuration and runtime settings
├── lib/            # Legacy or external PHP library helpers
├── private/        # User-specific assets (backgrounds, custom frames) - ignored in version control
├── resources/      # Compiled & minified public assets (CSS, JS, fonts, language files)
├── src/            # Core PHP classes, Enums, Factories, and Services (PSR-4 compliant)
├── template/       # UI templates and HTML/PHP view components
├── welcome/        # Startup dashboard / Welcome screen
├── package.json    # Node.js dependencies and NPM scripts
├── composer.json   # PHP dependencies
└── Dockerfile      # Instructions for building the container image
```

---

## 🛠 Quick Start (Docker)

The easiest way to run the application is by using Docker Compose.

1. Ensure Docker and Docker Compose are installed on your system.
2. Clone this repository.
3. Start the application:
   ```bash
   docker compose up --build -d
   ```
4. Access the application:
   - User Interface: `http://localhost`
   - Admin Panel: `http://localhost/admin`

*(Note: For camera functionality to work in modern web browsers, the application must be accessed via `http://localhost` or a secure `https://` origin).*
