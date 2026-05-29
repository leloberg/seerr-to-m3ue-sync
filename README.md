# Seerr to m3ue Sync Plugin

👾Just so you know, this plugin has been blatantly written with the vibiest vibe coding without any remorse. Not this part though. That is written by me, and I'm only human after all 🎵

A plugin for [m3u-editor](https://github.com/m3ue/m3u-editor) that reads approved requests from Seerr and enables matching movies and series in your library.

## How it works

- Pulls request pages from Seerr for movie, tv, or both media types.
- Routes movie requests to Channel matching in the configured movie playlist.
- Routes tv requests to Series matching in the configured series playlist.
- For matched series with requested seasons, enables episodes in requested seasons.
- Can probe matched movie variants and keep the best quality per title (with optional 4K inclusion).

## Requirements

- m3u-editor with plugin support enabled.
- A reachable Seerr instance.
- A valid Seerr API key.
- At least one target playlist configured (movie and or series depending on selected media type).

## Installation

Install via the m3u-editor Plugins page using the latest GitHub release, or via Artisan:

```bash
php artisan plugins:stage-github-release \
  https://github.com/<owner>/<repo>/releases/download/<tag>/seerr-to-m3ue-sync.zip \
  --sha256=<checksum>
```

Once staged, approve the install review in the UI and enable the plugin.

## Settings

| Setting | Default | Description |
|---|---|---|
| seerr_url | - | Base URL of your Seerr instance. |
| seerr_api_key | - | Seerr API key used for request and metadata endpoints. |
| target_movie_playlist | - | Playlist used when processing movie requests. |
| target_series_playlist | - | Playlist used when processing tv requests. |
| include_movies | true | Include movie requests in sync. |
| include_series | true | Include tv requests in sync. |
| enable_4k | false | Keep one 4K variant in addition to best non-4K variant when available. |
| probe_missing_streams | true | Probe missing stream stats before quality selection. |
| schedule_enabled | false | Enable scheduled sync runs. |
| schedule_cron | 0 * * * * | Cron expression used for scheduled sync. |

## Actions

| Action | Description |
|---|---|
| sync | Runs full synchronization and applies changes. |
| preview | Runs read-only preview and returns what would be enabled. |
| health_check | Tests Seerr connectivity and API key validity. |

## Hook behavior

- Subscribes to playlist.synced and triggers sync.

## Automatic scheduling

Enable schedule_enabled and set a cron expression in schedule_cron. The plugins:run-scheduled artisan command (run every minute by the host scheduler) will trigger sync when due.

## Releasing

```bash
php scripts/validate-plugin.php
bash scripts/package-plugin.sh
```

On Windows PowerShell you can package with:

```powershell
Compress-Archive -Path plugin.json, Plugin.php -DestinationPath dist\seerr-to-m3ue-sync.zip -Force
```

Publish the zip and checksum in your GitHub release notes whenever the archive changes.

## Runtime files

- plugin.json
- Plugin.php
