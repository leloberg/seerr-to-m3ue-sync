# Models & Database Reference

## m3u-editor Models Available to Plugins

Plugins have full Eloquent access. These are the key models:

### Channel

```php
use App\Models\Channel;

// Key fields: id, title, name, title_custom, name_custom, group, group_internal,
//             url, logo, playlist_id, enabled, channel_number, lang
// Relations: playlist(), epgMaps()

$channels = Channel::query()
    ->where('enabled', true)
    ->where('playlist_id', $playlistId)
    ->get();

// title_custom/name_custom are user overrides — never overwrite on re-import
// title_normalized is a separate column for matching (won't break unique constraint)
// Unique constraint: (title, name, group_internal, playlist_id)
```

### Playlist

```php
use App\Models\Playlist;

// Key fields: id, name, url, type, enabled, proxy_enabled, last_synced_at
// Relations: channels()

$playlists = Playlist::where('enabled', true)->get();
$channelCount = $playlist->channels()->count();
```

### Epg

```php
use App\Models\Epg;

// Key fields: id, name, url, type, enabled, last_synced_at
// Relations: epgMaps()

$epgSources = Epg::where('enabled', true)->get();
```

### EpgMap

```php
use App\Models\EpgMap;

// Key fields: id, channel_id, epg_id, epg_channel_id, confidence
// Relations: channel(), epg()
// This is the channel-to-EPG mapping table

$mappings = EpgMap::where('channel_id', $channel->id)->get();
```

### MergedPlaylist

```php
use App\Models\MergedPlaylist;

// Key fields: id, name, uuid, enabled, channel_count
// The output playlists that combine channels from multiple providers

$outputs = MergedPlaylist::where('enabled', true)->get();
```

### Plugin (self-reference)

```php
// Available via $context->plugin
$context->plugin->id;
$context->plugin->plugin_id;    // slug
$context->plugin->name;
$context->plugin->settings;     // saved settings array
$context->plugin->enabled;
```

### PluginRun (current run)

```php
// Available via $context->run
$context->run->id;
$context->run->status;          // 'running', 'completed', 'failed', 'cancelled'
$context->run->run_state;       // persistent state array
$context->run->progress;        // 0-100
$context->run->progress_message;
```

## Plugin-Owned Tables

If your plugin needs its own database tables, declare them in `plugin.json` under `schema.tables`. The host creates them when the plugin is trusted.

### Naming Rules

Table names must start with `plugin_{id}_` where dashes in the plugin id become underscores:

- Plugin id `seerr-to-m3ue-sync` → tables start with `plugin_seerr-to-m3ue-sync_` (dashes → underscores)

### Querying Plugin-Owned Tables

Use Laravel's DB facade or create a simple model:

```php
use Illuminate\Support\Facades\DB;

// Direct query
$results = DB::table('plugin_seerr-to-m3ue-sync_results')
    ->where('confidence', '>', 0.8)
    ->get();

// Or create a model inside your plugin directory
// (keep it in Plugin.php or a separate file referenced by the class)
```

### Supported Column Types

`id`, `foreignId`, `string`, `text`, `boolean`, `integer`, `bigInteger`, `decimal` (with precision/scale), `json`, `timestamp`, `timestamps`

### Supported Index Types

`index`, `unique` — applied to one or more columns

## File Storage

Plugin files go under approved roots only:

```php
use Illuminate\Support\Facades\Storage;

// Working data
Storage::disk('local')->put("plugin-data/seerr-to-m3ue-sync/state.json", $json);
$data = Storage::disk('local')->get("plugin-data/seerr-to-m3ue-sync/state.json");

// Reports
$path = Storage::disk('local')->path("plugin-reports/seerr-to-m3ue-sync/latest.html");
file_put_contents($path, $html);
```

Declare all paths in `data_ownership.directories` in `plugin.json`.
