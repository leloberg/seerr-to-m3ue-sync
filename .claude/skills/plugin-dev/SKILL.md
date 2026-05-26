---
name: plugin-dev
description: "Guide for developing the seerr-to-m3ue-sync plugin for m3u-editor. Use when writing plugin logic, adding actions, settings, hooks, database schema, or editing plugin.json. Covers PluginExecutionContext, PluginActionResult, contracts, manifest structure, and common patterns."
compatibility: Requires PHP 8.4+, Laravel 12, and access to m3u-editor's plugin system
metadata:
  author: m3u-editor
  version: "1.0"
---

# Seerr to m3ue Sync Plugin Development

## Plugin Structure

Two required files:
- `plugin.json` — manifest declaring capabilities, hooks, permissions, settings, schema, actions
- `Plugin.php` — entrypoint class implementing required interfaces

## Development Workflow

1. Edit `plugin.json` — declare what the plugin does (capabilities, hooks, permissions, settings, actions, schema)
2. Edit `Plugin.php` — implement the logic matching declared capabilities
3. Discover + Validate + Trust + Enable from the m3u-editor UI
4. Test by running actions from the plugin page

## Contract Methods

```php
// Required — every plugin (handles manual actions from UI)
public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult;

// Required if hooks declared in plugin.json
public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult;

// Required if 'scheduled' capability declared
public function scheduledActions(CarbonInterface $now, array $settings): array;

// Optional — cleanup during uninstall
public function uninstall(PluginUninstallContext $context): void;
```

## Returning Results

```php
PluginActionResult::success('Processed 42 channels.', ['count' => 42]);
PluginActionResult::failure('No channels found.', ['query' => $q]);
PluginActionResult::cancelled('Stopped after 20 of 42.', ['done' => 20]);
```

## Execution Context API

```php
// Logging — visible in "Live Activity" tab in UI
$context->info('Starting scan...');
$context->warning('Provider returned partial data');
$context->error('Connection failed');

// Progress bar (0-100)
$context->heartbeat('Batch 3/10', progress: 30);

// Checkpoint = heartbeat + log + persistent state
$context->checkpoint(50, 'Halfway', ['cursor' => $next], log: true);

// Read persistent state
$context->state('cursor');

// Check cancellation in loops
if ($context->cancellationRequested()) {
    return PluginActionResult::cancelled('User cancelled.');
}

// Context properties
$context->dryRun;     // true = preview mode
$context->settings;   // user's plugin settings from UI
$context->trigger;    // 'manual', 'hook', or 'schedule'
$context->hook;       // hook name if trigger is 'hook'
$context->user;       // User model (null for scheduled)
$context->plugin;     // Plugin model
$context->run;        // PluginRun model
```

## Common Patterns

Always check `$context->dryRun` — preview changes without writing:

```php
if ($context->dryRun) {
    return PluginActionResult::success("Would update {$count} channels.", compact('count'));
}
```

Always check cancellation in loops:

```php
foreach ($items->chunk(100) as $i => $batch) {
    if ($context->cancellationRequested()) {
        return PluginActionResult::cancelled("Stopped at batch {$i}.");
    }
    $context->checkpoint((int)(($i + 1) / $totalBatches * 100), "Batch " . ($i + 1), log: true);
}
```

## References

For detailed reference material, see:
- [Manifest Reference](references/manifest.md) — full plugin.json example, all fields, schema tables, settings, actions
- [Hooks & Capabilities](references/hooks-and-capabilities.md) — every hook with payload details, capability-to-interface mapping
- [Models & Database](references/models.md) — m3u-editor Eloquent models available to plugins, plugin-owned table naming
- `CLAUDE.md` — extended API reference with more code examples
- `AGENTS.md` — architecture rules, permission reference, security model
