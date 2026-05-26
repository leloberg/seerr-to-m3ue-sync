# Hooks & Capabilities Reference

## Capabilities

Capabilities determine which PHP interfaces the Plugin class must implement.

| Capability | Interface | What It Enables |
|-----------|-----------|----------------|
| `channel_processor` | `ChannelProcessorPluginInterface` | Process, transform, or analyze channels |
| `epg_processor` | `EpgProcessorPluginInterface` | Process, transform, or enrich EPG data |
| `stream_analysis` | `StreamAnalysisPluginInterface` | Analyze stream health and quality |
| `scheduled` | `ScheduledPluginInterface` | Run actions on a cron schedule |

All four capability interfaces extend `PluginInterface` — they're marker interfaces that signal intent. The actual work happens in `runAction()`.

`ScheduledPluginInterface` additionally requires implementing `scheduledActions()`:

```php
public function scheduledActions(CarbonInterface $now, array $settings): array
{
    if (! ($settings['schedule_enabled'] ?? false)) {
        return [];
    }

    $cron = (string) ($settings['schedule_cron'] ?? '');
    if ($cron === '' || ! CronExpression::isValidExpression($cron)) {
        return [];
    }

    if (! (new CronExpression($cron))->isDue($now)) {
        return [];
    }

    return [[
        'type' => 'action',
        'name' => 'scan',          // must match an action id in plugin.json
        'payload' => ['source' => 'schedule'],
        'dry_run' => false,
    ]];
}
```

## Hooks

Hooks are host events your plugin can subscribe to. Declare them in `plugin.json` under `hooks` and implement `HookablePluginInterface::runHook()`.

**Important**: Hooks require the `hook_subscriptions` permission. Hooks run as queued jobs with `dry_run: true` by default.

### playlist.synced

Fires after a playlist finishes importing or syncing channels.

```php
public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
{
    if ($hook === 'playlist.synced') {
        $context->info('Playlist sync completed, scanning new channels...');
        // $payload contains sync metadata
        // Access channels via Eloquent: Channel::where('playlist_id', $playlistId)
        return PluginActionResult::success('Post-sync scan complete.');
    }

    return PluginActionResult::success("Hook [{$hook}] acknowledged.");
}
```

### epg.synced

Fires after EPG data finishes syncing from configured sources.

### epg.cache.generated

Fires after EPG cache XML files are rebuilt. Use this to post-process or validate generated EPG output.

### before.epg.map / after.epg.map

Fires before and after the EPG mapping process runs. Use `before` to prepare data, `after` to validate results.

### before.epg.output.generate / after.epg.output.generate

Fires before and after EPG output generation. Use for pre-processing input data or post-processing generated files.

## Hook Implementation Pattern

```php
public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
{
    return match ($hook) {
        'playlist.synced' => $this->handlePlaylistSync($payload, $context),
        'epg.synced' => $this->handleEpgSync($payload, $context),
        default => PluginActionResult::success("Hook [{$hook}] acknowledged."),
    };
}
```

## Lifecycle Interfaces

### LifecyclePluginInterface (optional)

Implement this to run custom cleanup during uninstall. The host handles declarative cleanup (tables, directories) automatically — this is only for non-declarative resources.

```php
public function uninstall(PluginUninstallContext $context): void
{
    if (! $context->shouldPurge()) {
        return; // user chose "keep data"
    }

    // clean up anything not covered by data_ownership declarations
    // e.g., cache entries, external service registrations
}
```

`PluginUninstallContext` properties:
- `$context->plugin` — Plugin model
- `$context->cleanupMode` — `'preserve'` or `'purge'`
- `$context->dataOwnership` — the `data_ownership` array from plugin.json
- `$context->user` — User who triggered uninstall (nullable)
- `$context->shouldPurge()` — shorthand for `cleanupMode === 'purge'`
