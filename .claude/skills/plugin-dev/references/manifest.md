# Manifest Reference

## Complete plugin.json Example

```json
{
    "id": "seerr-to-m3ue-sync",
    "name": "Seerr to m3ue Sync",
    "version": "1.0.0",
    "api_version": "1.0.0",
    "description": "What this plugin does in one sentence.",
    "entrypoint": "Plugin.php",
    "class": "AppLocalPlugins\\SeerrToM3ueSync\\Plugin",
    "capabilities": ["channel_processor", "scheduled"],
    "hooks": ["playlist.synced"],
    "permissions": [
        "queue_jobs",
        "db_read",
        "db_write",
        "hook_subscriptions",
        "scheduled_runs"
    ],
    "schema": {
        "tables": [
            {
                "name": "plugin_seerr-to-m3ue-sync_results",
                "columns": [
                    {"name": "id", "type": "id"},
                    {"name": "channel_id", "type": "foreignId"},
                    {"name": "original_title", "type": "string"},
                    {"name": "normalized_title", "type": "string"},
                    {"name": "confidence", "type": "decimal", "precision": 5, "scale": 2},
                    {"name": "metadata", "type": "json", "nullable": true},
                    {"name": "timestamps", "type": "timestamps"}
                ],
                "indexes": [
                    {"columns": ["channel_id"], "type": "index"},
                    {"columns": ["normalized_title"], "type": "index"}
                ]
            }
        ]
    },
    "data_ownership": {
        "tables": ["plugin_seerr-to-m3ue-sync_results"],
        "directories": [
            "plugin-data/seerr-to-m3ue-sync",
            "plugin-reports/seerr-to-m3ue-sync"
        ],
        "files": [],
        "default_cleanup_policy": "preserve"
    },
    "settings": [
        {
            "id": "batch_size",
            "label": "Batch Size",
            "type": "number",
            "default": 100
        },
        {
            "id": "mode",
            "label": "Matching Mode",
            "type": "select",
            "default": "fuzzy",
            "options": [
                {"value": "strict", "label": "Strict (exact match)"},
                {"value": "fuzzy", "label": "Fuzzy (similarity threshold)"}
            ]
        },
        {
            "id": "target_playlist",
            "label": "Target Playlist",
            "type": "model_select",
            "model": "App\\Models\\Playlist",
            "label_attribute": "name"
        },
        {
            "id": "auto_apply",
            "label": "Auto-Apply Changes",
            "type": "boolean",
            "default": false
        },
        {
            "id": "schedule_enabled",
            "label": "Enable Scheduled Runs",
            "type": "boolean",
            "default": false
        },
        {
            "id": "schedule_cron",
            "label": "Schedule (Cron Expression)",
            "type": "text",
            "default": "0 * * * *"
        }
    ],
    "actions": [
        {
            "id": "scan",
            "label": "Scan Channels",
            "dry_run": false,
            "requires_confirmation": true,
            "fields": []
        },
        {
            "id": "preview",
            "label": "Preview Changes",
            "dry_run": true,
            "fields": [
                {"id": "limit", "label": "Max Results", "type": "number", "default": 50}
            ]
        }
    ]
}
```

## Required Manifest Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Must match directory name, valid slug |
| `name` | string | Display name shown in UI |
| `entrypoint` | string | PHP file containing the plugin class |
| `class` | string | Fully qualified class name: `AppLocalPlugins\{StudlyName}\Plugin` |

## Important Manifest Fields

| Field | Type | Description |
|-------|------|-------------|
| `api_version` | string | Must be `1.0.0` (current host version) |
| `version` | string | Plugin version (semver) |
| `capabilities` | string[] | Determines required interfaces |
| `hooks` | string[] | Host events to subscribe to |
| `permissions` | string[] | Explicit access declarations |
| `schema.tables` | object[] | Database tables the host creates on trust |
| `settings` | object[] | User-configurable fields rendered in UI |
| `actions` | object[] | Manual buttons on the plugin page |
| `data_ownership` | object | Tables/files/directories managed on uninstall |

## Schema Table Rules

- Table names must start with `plugin_{plugin_id}_` (dashes become underscores)
- Supported column types: `id`, `foreignId`, `string`, `text`, `boolean`, `integer`, `bigInteger`, `decimal`, `json`, `timestamp`, `timestamps`
- Supported index types: `index`, `unique`
- The host creates tables on trust — plugins never run raw migrations
- Declared in `schema.tables` and also listed in `data_ownership.tables`

## Settings Field Types

| Type | Renders As | Extra Fields |
|------|-----------|-------------|
| `boolean` | Toggle switch | — |
| `number` | Numeric input | — |
| `text` | Single-line text input | — |
| `textarea` | Multi-line text area | — |
| `select` | Dropdown menu | `options: [{value, label}]` |
| `model_select` | Eloquent model picker | `model`, `label_attribute`, optional `scope: "owned"` |

## Action Field Types

Action fields use the same types as settings. They become the `$payload` parameter in `runAction()`.

## Permissions Reference

| Permission | When Required |
|-----------|--------------|
| `queue_jobs` | Almost always — runs actions as background jobs |
| `db_read` | Reading m3u-editor's database via Eloquent |
| `db_write` | Writing to m3u-editor's database |
| `schema_manage` | Declaring tables in `schema.tables` |
| `filesystem_read` | Reading plugin-owned files |
| `filesystem_write` | Writing plugin-owned files |
| `network_egress` | Calling external APIs (declarative, not enforced) |
| `hook_subscriptions` | Required if `hooks` array is non-empty |
| `scheduled_runs` | Required if `scheduled` capability is declared |

High-risk permissions that get extra scrutiny: `network_egress`, `filesystem_write`, `schema_manage`.

## File Storage Paths

Plugin files must live under approved roots:
- `plugin-data/{plugin-id}/` — working data, caches, state files
- `plugin-reports/{plugin-id}/` — generated reports, exports

```php
use Illuminate\Support\Facades\Storage;

Storage::disk('local')->put("plugin-data/seerr-to-m3ue-sync/cache.json", json_encode($data));
```

Declare paths in `data_ownership.directories` so uninstall can clean them up.
