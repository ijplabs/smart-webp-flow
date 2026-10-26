# Smart WebP Flow & Media Sync

[![CI](https://github.com/ijplabs/smart-webp-flow/actions/workflows/ci.yml/badge.svg)](https://github.com/ijplabs/smart-webp-flow/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF.svg)](https://php.net)
[![WordPress Version](https://img.shields.io/badge/WordPress-%3E%3D%205.8-21759B.svg)](https://wordpress.org)

Lightweight, robust, and modern WebP image conversion and synchronization engine for WordPress.

Converts JPG/PNG media uploads automatically, provides non-blocking chunked bulk synchronization with zero website downtime, and seamlessly synchronizes image URLs inside post content.

---

## 🚀 Key Features

* **Instant Upload Interception**: Automatically converts uploaded JPG and PNG files into high-efficiency WebP format across masters and all registered thumbnail sub-sizes.
* **Safe Retention Policy (Default)**: Automatically preserves original JPG/PNG files as backup (`keep_original`) to ensure full rollback capabilities without risking permanent data loss.
* **Autonomous Bulk Synchronization**:
  * Keyset-based pagination (`ID > :last_id`) avoiding heavy database offset penalties.
  * Concurrency transient mutex lock to prevent worker collisions.
  * Single-use cryptographic dispatch tokens for self-looping asynchronous background conversion.
  * Real-time server telemetry pacing to maintain 0% CPU spikes for site visitors.
* **Dual-Layer Post Content Synchronizer**:
  * *Dynamic Frontend Filter*: Automatic on-the-fly replacement of image URLs in `the_content` on frontend without touching the database.
  * *Database Content Migrator Wizard*: Step-by-step scanner with dry-run diff preview and atomic post rollback snapshots.
* **Intuitive Admin Dashboard**: Native WordPress dashboard tabs with real-time server health metrics, bulk sync progress, migrator wizard, and granular compression parameters.

---

## 📋 System Requirements

* **WordPress**: 5.8 or higher
* **PHP**: 7.4 or higher (fully tested on PHP 8.0, 8.2, and 8.5)
* **Image Extensions**: PHP `Imagick` (recommended) or PHP `GD` with WebP support
* **Optional**: External REST API microservice driver for remote computing offload

---

## 🛠️ Architecture & Directory Structure

```text
smart-webp-flow/
├── assets/
│   ├── css/admin.css       # Native WordPress dashboard styling
│   └── js/admin.js         # Tab switching and REST polling client
├── src/
│   ├── Admin/              # Dashboard pages and notices
│   ├── Background/         # Mutex locking, throttling, async worker
│   ├── Common/             # Constants and configurations
│   ├── Controllers/        # WordPress REST API v1 endpoints
│   ├── Engines/            # Imagick, GD, and External API drivers
│   ├── Services/           # Upload interceptor, metadata, post content
│   ├── Autoloader.php      # PSR-4 Autoloader
│   └── Plugin.php          # Singleton kernel container
├── smart-webp-flow.php     # Main plugin entry point
└── uninstall.php           # Clean uninstaller
```

---

## 📦 Distribution & Releases

Official production releases are packaged as standalone ZIP archives and distributed via:
1. **GitHub Releases**: [https://github.com/ijplabs/smart-webp-flow/releases](https://github.com/ijplabs/smart-webp-flow/releases)
2. **RustFS S3 Distribution Bucket**: `s3://ijplabs-wordpress/plugins/smart-webp-flow/`

---

## 📄 License

This plugin is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
