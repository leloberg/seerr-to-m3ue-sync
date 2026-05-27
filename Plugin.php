<?php

namespace AppLocalPlugins\SeerrToM3ueSync;

use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\LifecyclePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\Support\PluginUninstallContext;
use App\Services\StreamStatsService;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\Http;

class Plugin implements PluginInterface, ScheduledPluginInterface, HookablePluginInterface, LifecyclePluginInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'sync'         => $this->sync($payload, $context),
            'preview'      => $this->sync($payload, $context),
            'health_check' => $this->healthCheck($context),
            default        => PluginActionResult::failure("Unsupported action [{$action}]"),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($hook) {
            'playlist.synced' => $this->sync([], $context),
            default           => PluginActionResult::success("Hook [{$hook}] acknowledged."),
        };
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
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
            'type'    => 'action',
            'name'    => 'sync',
            'payload' => ['source' => 'schedule'],
            'dry_run' => false,
        ]];
    }

    public function uninstall(PluginUninstallContext $context): void
    {
        if (! $context->shouldPurge()) {
            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sync(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $seerrUrl = rtrim((string) ($settings['seerr_url'] ?? ''), '/');
        $apiKey = (string) ($settings['seerr_api_key'] ?? '');
        $legacyPlaylistId = isset($settings['target_playlist']) ? (int) $settings['target_playlist'] : null;
        $moviePlaylistId = isset($settings['target_movie_playlist'])
            ? (int) $settings['target_movie_playlist']
            : $legacyPlaylistId;
        $seriesPlaylistId = isset($settings['target_series_playlist'])
            ? (int) $settings['target_series_playlist']
            : $legacyPlaylistId;
        $mediaTypes = $this->resolveMediaTypes($settings);
        $enable4k = (bool) ($settings['enable_4k'] ?? false);
        $probeMissing = (bool) ($settings['probe_missing_streams'] ?? true);
        $disableUnselectedVariants = (bool) ($settings['disable_unselected_variants'] ?? true);
        $syncVodStrmFiles = (bool) ($settings['sync_vod_strm_files'] ?? false);
        $syncSeriesStrmFiles = (bool) ($settings['sync_series_strm_files'] ?? false);
        $isDryRun = (bool) $context->dryRun;
        $allowSideEffects = ! $isDryRun;

        if ($seerrUrl === '' || $apiKey === '') {
            return PluginActionResult::failure('Seerr URL and API key are required in plugin settings.');
        }

        if (in_array($mediaTypes, ['movie', 'both'], true) && ! $moviePlaylistId) {
            return PluginActionResult::failure('No target movie playlist configured in plugin settings.');
        }

        if (in_array($mediaTypes, ['tv', 'both'], true) && ! $seriesPlaylistId) {
            return PluginActionResult::failure('No target series playlist configured in plugin settings.');
        }

        $moviePlaylist = null;
        if ($moviePlaylistId) {
            $moviePlaylist = Playlist::find($moviePlaylistId);
            if ($moviePlaylist === null) {
                return PluginActionResult::failure("Target movie playlist [{$moviePlaylistId}] not found.");
            }
        }

        $seriesPlaylist = null;
        if ($seriesPlaylistId) {
            $seriesPlaylist = Playlist::find($seriesPlaylistId);
            if ($seriesPlaylist === null) {
                return PluginActionResult::failure("Target series playlist [{$seriesPlaylistId}] not found.");
            }
        }

        $probeTimeout = (int) (($moviePlaylist?->probe_timeout) ?? 15);

        $context->info('Starting Seerr to m3ue Sync...');
        $context->info('Run mode: ' . ($isDryRun ? 'preview (read-only)' : 'sync (apply changes)'));
        $context->info('Effective settings: ' . json_encode([
            'media_types' => $mediaTypes,
            'movie_playlist' => $moviePlaylist?->name,
            'series_playlist' => $seriesPlaylist?->name,
            'enable_4k' => $enable4k,
            'probe_missing_streams' => $probeMissing,
            'probe_timeout' => $probeTimeout,
            'disable_unselected_variants' => $disableUnselectedVariants,
            'sync_vod_strm_files' => $syncVodStrmFiles,
            'sync_series_strm_files' => $syncSeriesStrmFiles,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $context->heartbeat('Fetching TMDB catalog from Seerr...', progress: 5);

        $seerrCatalog = $this->fetchSeerrCatalog(
            $seerrUrl,
            $apiKey,
            $mediaTypes,
            $context,
        );

        if ($seerrCatalog === null) {
            return PluginActionResult::failure('Failed to connect to Seerr API. Check URL and API key in settings.');
        }

        $tmdbIds = $seerrCatalog['ids'];
        $seerrRequestedItems = $seerrCatalog['items'];
        $movieTmdbIds = $seerrCatalog['by_type']['movie']['ids'] ?? [];
        $movieItems = $seerrCatalog['by_type']['movie']['items'] ?? [];
        $seriesTmdbIds = $seerrCatalog['by_type']['tv']['ids'] ?? [];
        $seriesItems = $seerrCatalog['by_type']['tv']['items'] ?? [];
        $movieAvailabilityByTmdb = [];
        $seriesAvailabilityByTmdb = [];
        $movieSkippedFullyAvailableCount = 0;
        $seriesSkippedFullyAvailableCount = 0;
        $seriesSkippedPartialWithoutRequestedSeasons = 0;
        $requestedSeriesSeasonsByTmdb = [];

        foreach ($movieItems as $item) {
            $itemTmdbId = (int) ($item['tmdb_id'] ?? 0);
            if ($itemTmdbId <= 0) {
                continue;
            }

            $movieAvailabilityByTmdb[$itemTmdbId] = (int) ($item['availability_status'] ?? 0);
        }

        foreach ($seriesItems as $item) {
            $itemTmdbId = (int) ($item['tmdb_id'] ?? 0);
            if ($itemTmdbId <= 0) {
                continue;
            }

            $seriesAvailabilityByTmdb[$itemTmdbId] = (int) ($item['availability_status'] ?? 0);
        }

        $movieTmdbIds = array_values(array_filter(
            $movieTmdbIds,
            function (int $tmdbId) use ($movieAvailabilityByTmdb, &$movieSkippedFullyAvailableCount): bool {
                $status = (int) ($movieAvailabilityByTmdb[$tmdbId] ?? 0);
                if ($status === 5) {
                    $movieSkippedFullyAvailableCount++;

                    return false;
                }

                return true;
            }
        ));

        $seriesTmdbIds = array_values(array_filter(
            $seriesTmdbIds,
            function (int $tmdbId) use ($seriesAvailabilityByTmdb, &$seriesSkippedFullyAvailableCount): bool {
                $status = (int) ($seriesAvailabilityByTmdb[$tmdbId] ?? 0);
                if ($status === 5) {
                    $seriesSkippedFullyAvailableCount++;

                    return false;
                }

                return true;
            }
        ));

        foreach ($seriesItems as $item) {
            $seriesTmdbId = (int) ($item['tmdb_id'] ?? 0);
            if ($seriesTmdbId <= 0) {
                continue;
            }

            $requestedSeasons = is_array($item['requested_seasons'] ?? null)
                ? array_values(array_unique(array_map('intval', $item['requested_seasons'])))
                : [];
            sort($requestedSeasons);

            if (! isset($requestedSeriesSeasonsByTmdb[$seriesTmdbId])) {
                $requestedSeriesSeasonsByTmdb[$seriesTmdbId] = $requestedSeasons;
                continue;
            }

            $merged = array_values(array_unique(array_merge($requestedSeriesSeasonsByTmdb[$seriesTmdbId], $requestedSeasons)));
            sort($merged);
            $requestedSeriesSeasonsByTmdb[$seriesTmdbId] = $merged;
        }

        if ($context->cancellationRequested()) {
            return PluginActionResult::cancelled('Cancelled while fetching TMDB IDs from Seerr.', [
                'tmdb_id_count' => count($tmdbIds),
            ]);
        }

        $context->info('Found ' . count($tmdbIds) . ' available TMDB ID(s) in Seerr.');
        if ($movieSkippedFullyAvailableCount > 0) {
            $context->info('Skipping ' . $movieSkippedFullyAvailableCount . ' movie item(s) already fully available in Seerr/Jellyfin (media.status=5).');
        }
        if ($seriesSkippedFullyAvailableCount > 0) {
            $context->info('Skipping ' . $seriesSkippedFullyAvailableCount . ' series item(s) already fully available in Seerr/Jellyfin (media.status=5).');
        }
        if ($tmdbIds !== []) {
            $context->info('Seerr TMDB IDs: ' . json_encode($tmdbIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if ($seerrRequestedItems !== []) {
            $context->info('Seerr requested items (tmdbId/title/type/requested_seasons): ' . json_encode($seerrRequestedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $context->checkpoint(40, 'Seerr TMDB IDs fetched', [
            'tmdb_id_count' => count($tmdbIds),
            'movie_tmdb_id_count' => count($movieTmdbIds),
            'series_tmdb_id_count' => count($seriesTmdbIds),
        ], log: true);

        if (count($tmdbIds) === 0) {
            return PluginActionResult::success('No titles found in Seerr for the configured filter — nothing to sync.', [
                'media_types' => $mediaTypes,
                'movie_tmdb_ids' => $movieTmdbIds,
                'series_tmdb_ids' => $seriesTmdbIds,
            ]);
        }

        $matchedMovieChannels = [];
        $matchedMovieTmdbIds = [];
        $movieChannelCount = 0;

        if ($moviePlaylist !== null && $movieTmdbIds !== []) {
            $movieTmdbIdSet = array_fill_keys($movieTmdbIds, true);
            $channels = Channel::query()
                ->where('playlist_id', $moviePlaylist->id)
                ->get();
            $movieChannelCount = $channels->count();

            $context->info("Scanning {$movieChannelCount} movie channel(s) in \"{$moviePlaylist->name}\"...");

            $processed = 0;
            foreach ($channels as $channel) {
                if ($context->cancellationRequested()) {
                    return PluginActionResult::cancelled(
                        "Cancelled during movie matching at channel {$processed}/{$movieChannelCount}.",
                        ['matched_movie_channels_so_far' => count($matchedMovieChannels)],
                    );
                }

                $processed++;
                if ($processed % 100 === 0) {
                    $context->heartbeat("Matching movie channels {$processed}/{$movieChannelCount}...", progress: 58);
                }

                $channelTmdbId = $channel->getTmdbId();
                $channelTmdbId = is_numeric($channelTmdbId) ? (int) $channelTmdbId : null;

                if ($channelTmdbId !== null && $channelTmdbId > 0 && isset($movieTmdbIdSet[$channelTmdbId])) {
                    $matchedMovieChannels[] = $channel;
                    $matchedMovieTmdbIds[$channelTmdbId] = true;
                }
            }

            $context->info('Matched ' . count($matchedMovieChannels) . ' movie channel(s).');
            if ($matchedMovieTmdbIds !== []) {
                $context->info('Matched movie TMDB IDs: ' . json_encode(array_keys($matchedMovieTmdbIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        $matchedSeries = [];
        $matchedSeriesTmdbIds = [];
        $seriesCount = 0;

        if ($seriesPlaylist !== null && $seriesTmdbIds !== []) {
            $seriesTmdbIdSet = array_fill_keys($seriesTmdbIds, true);
            $playlistSeries = Series::query()
                ->where('playlist_id', $seriesPlaylist->id)
                ->get();
            $seriesCount = $playlistSeries->count();

            $context->info("Scanning {$seriesCount} series in \"{$seriesPlaylist->name}\"...");

            foreach ($playlistSeries as $index => $series) {
                if ($context->cancellationRequested()) {
                    return PluginActionResult::cancelled('Cancelled during series matching.', [
                        'matched_series_so_far' => count($matchedSeries),
                    ]);
                }

                if (($index + 1) % 100 === 0) {
                    $context->heartbeat("Matching series " . ($index + 1) . "/{$seriesCount}...", progress: 66);
                }

                $seriesTmdbId = $this->resolveSeriesTmdbId($series);
                if ($seriesTmdbId !== null && isset($seriesTmdbIdSet[$seriesTmdbId])) {
                    $matchedSeries[] = $series;
                    $matchedSeriesTmdbIds[$seriesTmdbId] = true;
                }
            }

            $context->info('Matched ' . count($matchedSeries) . ' series.');
            if ($matchedSeriesTmdbIds !== []) {
                $context->info('Matched series TMDB IDs: ' . json_encode(array_keys($matchedSeriesTmdbIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        if ($isDryRun && $probeMissing) {
            $context->info('Preview mode: probing runs without persisting stream_stats to the database.');
        }

        $context->heartbeat('Probing matched movie channels...', progress: 70);
        $probeReport = $this->probeMatchedChannelsByTmdb(
            $matchedMovieChannels,
            $probeMissing,
            $probeTimeout,
            $context,
            $allowSideEffects,
        );

        if ($context->cancellationRequested()) {
            return PluginActionResult::cancelled('Cancelled during stream probing.', [
                'matched_movie_channels' => count($matchedMovieChannels),
                'probe_report' => $probeReport,
            ]);
        }

        [$selectedMovieChannelIds, $qualitySummary] = $this->selectChannelsByQuality(
            $matchedMovieChannels,
            $enable4k,
            $probeMissing,
            $allowSideEffects,
            $context,
        );
        $selectedMovieCount = count($selectedMovieChannelIds);

        if ($context->cancellationRequested()) {
            return PluginActionResult::cancelled('Cancelled during quality selection.', [
                'matched_movie_channels' => count($matchedMovieChannels),
                'selected_movie_channels_so_far' => $selectedMovieCount,
            ]);
        }

        $context->info("Selected {$selectedMovieCount} movie channel(s) after quality filtering.");
        if ($qualitySummary !== []) {
            $context->info('Selected qualities: ' . json_encode($qualitySummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $context->checkpoint(85, 'Matching complete', [
            'matched_movie_channels' => count($matchedMovieChannels),
            'matched_series' => count($matchedSeries),
        ], log: true);

        if ($isDryRun) {
            $selectedSet = array_fill_keys($selectedMovieChannelIds, true);
            $selectedChannels = array_values(array_filter(
                $matchedMovieChannels,
                fn (Channel $c) => isset($selectedSet[$c->id])
            ));

            $previewChannels = array_slice(
                array_map(fn ($c) => ($c->title_custom ?: $c->title ?: $c->name_custom ?: $c->name), $selectedChannels),
                0,
                100,
            );
            $previewSeries = array_slice(
                array_map(fn ($s) => (string) ($s->name ?? $s->title ?? 'unknown'), $matchedSeries),
                0,
                100,
            );

            return PluginActionResult::success(
                "Preview: {$selectedMovieCount} movie channel(s) and " . count($matchedSeries) . " series would be enabled.",
                [
                    'movie_playlist' => $moviePlaylist?->name,
                    'series_playlist' => $seriesPlaylist?->name,
                    'matched_channels' => $previewChannels,
                    'matched_series' => $previewSeries,
                    'total_seerr_tmdb_ids' => count($tmdbIds),
                    'movie_seerr_items' => $movieItems,
                    'series_seerr_items' => $seriesItems,
                    'seerr_requested_items' => $seerrRequestedItems,
                    'matched_movie_tmdb_ids' => array_values(array_map('intval', array_keys($matchedMovieTmdbIds))),
                    'matched_series_tmdb_ids' => array_values(array_map('intval', array_keys($matchedSeriesTmdbIds))),
                    'enable_4k' => $enable4k,
                    'selected_qualities' => $qualitySummary,
                    'probe_report' => $probeReport,
                ],
            );
        }

        $movieEnabledCount = 0;
        $movieDisabledCount = 0;
        $selectedSet = array_fill_keys($selectedMovieChannelIds, true);
        $applyTotal = count($matchedMovieChannels);
        $applyProcessed = 0;

        foreach ($matchedMovieChannels as $channel) {
            $applyProcessed++;

            if ($context->cancellationRequested()) {
                return PluginActionResult::cancelled(
                    "Cancelled while applying movie channel changes at {$applyProcessed}/{$applyTotal}.",
                    ['movie_enabled_so_far' => $movieEnabledCount, 'movie_disabled_so_far' => $movieDisabledCount],
                );
            }

            if ($applyProcessed % 50 === 0) {
                $context->heartbeat("Applying movie channel changes {$applyProcessed}/{$applyTotal}...", progress: 92);
            }

            $shouldEnable = isset($selectedSet[$channel->id]);
            if ($shouldEnable && ! $channel->enabled) {
                $channel->enabled = true;
                $channel->save();
                $movieEnabledCount++;
            }

            if ($disableUnselectedVariants && ! $shouldEnable && $channel->enabled) {
                $channel->enabled = false;
                $channel->save();
                $movieDisabledCount++;
            }
        }

        $seriesEnabledCount = 0;
        $seriesEpisodesEnabledCount = 0;
        $seriesEpisodesDisabledCount = 0;
        $seriesSeasonApplied = [];
        $seriesMetadataFetchAttempts = 0;
        $seriesMetadataFetchSucceeded = 0;
        foreach ($matchedSeries as $series) {
            if ($context->cancellationRequested()) {
                return PluginActionResult::cancelled('Cancelled while applying series changes.', [
                    'series_enabled_so_far' => $seriesEnabledCount,
                    'series_episode_enabled_so_far' => $seriesEpisodesEnabledCount,
                    'series_episode_disabled_so_far' => $seriesEpisodesDisabledCount,
                ]);
            }

            if (! (bool) ($series->enabled ?? false)) {
                $series->enabled = true;
                $series->save();
                $seriesEnabledCount++;
            }

            $seriesTmdbId = $this->resolveSeriesTmdbId($series);
            $seriesAvailabilityStatus = $seriesTmdbId !== null
                ? (int) ($seriesAvailabilityByTmdb[$seriesTmdbId] ?? 0)
                : 0;

            if ($seriesAvailabilityStatus === 5) {
                // Fully available titles should not be re-enabled/toggled by this plugin.
                continue;
            }

            $requestedSeasons = ($seriesTmdbId !== null && isset($requestedSeriesSeasonsByTmdb[$seriesTmdbId]))
                ? $requestedSeriesSeasonsByTmdb[$seriesTmdbId]
                : [];

            if ($seriesAvailabilityStatus === 4 && $requestedSeasons === []) {
                // Partial availability should only be processed when specific requested seasons are provided.
                $seriesSkippedPartialWithoutRequestedSeasons++;
                continue;
            }

            if ($requestedSeasons === []) {
                continue;
            }

            $hasSeasonRows = Season::query()->where('series_id', $series->id)->exists();
            $hasEpisodeRows = Episode::query()->where('series_id', $series->id)->exists();

            if (! $hasSeasonRows || ! $hasEpisodeRows) {
                $seriesMetadataFetchAttempts++;
                $context->info(
                    'Series ' . ($series->name ?? $series->id)
                    . ' is missing season/episode metadata. Fetching metadata before applying requested seasons...'
                );

                try {
                    if (method_exists($series, 'fetchMetadata')) {
                        // Force a fresh metadata sync so seasons/episodes exist before season-specific enabling.
                        $series->fetchMetadata(true, true, true);
                    }

                    $series = $series->fresh() ?? $series;
                    $hasSeasonRows = Season::query()->where('series_id', $series->id)->exists();
                    $hasEpisodeRows = Episode::query()->where('series_id', $series->id)->exists();

                    if ($hasSeasonRows && $hasEpisodeRows) {
                        $seriesMetadataFetchSucceeded++;
                        $context->info('Metadata fetched for series ' . ($series->name ?? $series->id) . '. Continuing with requested season apply.');
                    } else {
                        $context->warning(
                            'Metadata fetch completed but seasons/episodes are still missing for series '
                            . ($series->name ?? $series->id)
                            . '. Requested season apply may be partial until metadata job completes.'
                        );
                    }
                } catch (\Throwable $e) {
                    $context->warning(
                        'Failed to fetch metadata for series '
                        . ($series->name ?? $series->id)
                        . ': ' . $e->getMessage()
                    );
                }
            }

            $seriesSeasonApplied[(string) $series->id] = $requestedSeasons;
            $requestedSeasonNumbersSet = array_fill_keys($requestedSeasons, true);
            $requestedSeasonIds = Season::query()
                ->where('series_id', $series->id)
                ->whereIn('season_number', $requestedSeasons)
                ->pluck('id')
                ->all();
            $requestedSeasonIdsSet = array_fill_keys(array_map('intval', $requestedSeasonIds), true);

            $seriesEpisodeQuery = Episode::query()->where('series_id', $series->id);
            $seriesEpisodeTotal = (clone $seriesEpisodeQuery)->count();
            $seriesEpisodeProcessed = 0;

            foreach ($seriesEpisodeQuery->lazyById(200) as $episode) {
                if ($context->cancellationRequested()) {
                    return PluginActionResult::cancelled('Cancelled while applying requested seasons to episodes.', [
                        'series_enabled_so_far' => $seriesEnabledCount,
                        'series_episode_enabled_so_far' => $seriesEpisodesEnabledCount,
                        'series_episode_disabled_so_far' => $seriesEpisodesDisabledCount,
                    ]);
                }

                $seriesEpisodeProcessed++;
                if ($seriesEpisodeProcessed % 200 === 0) {
                    $context->heartbeat("Applying requested seasons to series {$series->id}: {$seriesEpisodeProcessed}/{$seriesEpisodeTotal} episodes...", progress: 96);
                }

                $episodeSeasonId = (int) ($episode->season_id ?? 0);
                $episodeSeasonNumber = (int) ($episode->season ?? 0);
                $shouldEnableEpisode = (
                    $episodeSeasonId > 0 && isset($requestedSeasonIdsSet[$episodeSeasonId])
                ) || (
                    $episodeSeasonNumber > 0 && isset($requestedSeasonNumbersSet[$episodeSeasonNumber])
                );

                if ($shouldEnableEpisode && ! (bool) ($episode->enabled ?? false)) {
                    $episode->enabled = true;
                    $episode->save();
                    $seriesEpisodesEnabledCount++;
                    continue;
                }

                if (! $shouldEnableEpisode && $disableUnselectedVariants && (bool) ($episode->enabled ?? false)) {
                    $episode->enabled = false;
                    $episode->save();
                    $seriesEpisodesDisabledCount++;
                }
            }

            $context->info(
                'Applied requested seasons for series ' . ($series->name ?? $series->id)
                . ' (TMDB ' . ($seriesTmdbId ?? 'unknown') . '): '
                . json_encode($requestedSeasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $dispatchedStrmJobs = [
            'vod' => false,
            'series' => false,
        ];

        if ($syncVodStrmFiles && $moviePlaylist !== null && $selectedMovieChannelIds !== []) {
            SyncVodStrmFiles::dispatch(
                notify: false,
                playlist_id: $moviePlaylist->id,
                user_id: $moviePlaylist->user_id,
                channel_ids: $selectedMovieChannelIds,
            );

            $dispatchedStrmJobs['vod'] = true;
            $context->info('Queued VOD .strm sync for ' . count($selectedMovieChannelIds) . ' selected channel(s).');
        }

        $matchedSeriesIds = array_values(array_map(
            static fn (Series $series): int => (int) $series->id,
            $matchedSeries,
        ));

        if ($syncSeriesStrmFiles && $seriesPlaylist !== null && $matchedSeriesIds !== []) {
            SyncSeriesStrmFiles::dispatch(
                notify: false,
                playlist_id: $seriesPlaylist->id,
                user_id: $seriesPlaylist->user_id,
                series_ids: $matchedSeriesIds,
            );

            $dispatchedStrmJobs['series'] = true;
            $context->info('Queued series .strm sync for ' . count($matchedSeriesIds) . ' matched series.');
        }

        $context->checkpoint(100, 'Done', log: true);

        return PluginActionResult::success(
            "Sync complete — {$movieEnabledCount} movie channel(s) and {$seriesEnabledCount} series enabled.",
            [
                'movie_playlist' => $moviePlaylist?->name,
                'series_playlist' => $seriesPlaylist?->name,
                'movie_channels_scanned' => $movieChannelCount,
                'movie_channels_matched' => count($matchedMovieChannels),
                'movie_channels_selected' => $selectedMovieCount,
                'movie_channels_newly_enabled' => $movieEnabledCount,
                'movie_channels_newly_disabled' => $movieDisabledCount,
                'series_scanned' => $seriesCount,
                'series_matched' => count($matchedSeries),
                'series_newly_enabled' => $seriesEnabledCount,
                'series_requested_seasons_applied' => $seriesSeasonApplied,
                'series_skipped_partial_without_requested_seasons' => $seriesSkippedPartialWithoutRequestedSeasons,
                'series_episodes_newly_enabled' => $seriesEpisodesEnabledCount,
                'series_episodes_newly_disabled' => $seriesEpisodesDisabledCount,
                'series_metadata_fetch_attempts' => $seriesMetadataFetchAttempts,
                'series_metadata_fetch_succeeded' => $seriesMetadataFetchSucceeded,
                'movie_skipped_fully_available' => $movieSkippedFullyAvailableCount,
                'series_skipped_fully_available' => $seriesSkippedFullyAvailableCount,
                'seerr_tmdb_id_count' => count($tmdbIds),
                'movie_seerr_tmdb_ids' => $movieTmdbIds,
                'series_seerr_tmdb_ids' => $seriesTmdbIds,
                'movie_seerr_items' => $movieItems,
                'series_seerr_items' => $seriesItems,
                'seerr_requested_items' => $seerrRequestedItems,
                'matched_movie_tmdb_ids' => array_values(array_map('intval', array_keys($matchedMovieTmdbIds))),
                'matched_series_tmdb_ids' => array_values(array_map('intval', array_keys($matchedSeriesTmdbIds))),
                'enable_4k' => $enable4k,
                'selected_qualities' => $qualitySummary,
                'probe_report' => $probeReport,
                'strm_sync_requested' => [
                    'vod' => $syncVodStrmFiles,
                    'series' => $syncSeriesStrmFiles,
                ],
                'strm_sync_dispatched' => $dispatchedStrmJobs,
            ],
        );
    }

    /**
     * @param array<Channel> $matchedChannels
     * @return array{0: array<int>, 1: array<string, int>}
     */
    private function selectChannelsByQuality(
        array $matchedChannels,
        bool $enable4k,
        bool $probeMissing,
        bool $allowSideEffects,
        PluginExecutionContext $context,
    ): array {
        $grouped = [];
        foreach ($matchedChannels as $channel) {
            $tmdbId = $channel->getTmdbId();
            if ($tmdbId === null) {
                continue;
            }

            $groupKey = $this->buildSelectionGroupKey($channel, $tmdbId);
            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'tmdb_id' => $tmdbId,
                    'channels' => [],
                ];
            }

            $grouped[$groupKey]['channels'][] = $channel;
        }

        $selectedIds = [];
        $qualityCounts = [];
        $groupCount = count($grouped);
        $groupIndex = 0;

        foreach ($grouped as $groupKey => $group) {
            $groupIndex++;

            if ($context->cancellationRequested()) {
                break;
            }

            if ($groupIndex % 25 === 0) {
                $context->heartbeat("Selecting qualities {$groupIndex}/{$groupCount} TMDB groups...", progress: 80);
            }

            $tmdbId = (int) ($group['tmdb_id'] ?? 0);
            $variants = is_array($group['channels'] ?? null) ? $group['channels'] : [];

            $entries = [];

            foreach ($variants as $channel) {
                $quality = $this->detectChannelQuality($channel, $probeMissing, $allowSideEffects);
                $bitrate = $this->detectChannelBitrate($channel);

                $entries[] = [
                    'channel' => $channel,
                    'quality' => $quality,
                    'bitrate' => $bitrate,
                ];
            }

            if ($entries === []) {
                continue;
            }

            $perTitlePicked = [];

            if ($enable4k) {
                $picked4k = $this->pickBestByQuality($entries, '4k');
                if ($picked4k !== null) {
                    $perTitlePicked[$picked4k['channel']->id] = true;
                    $selectedIds[] = $picked4k['channel']->id;
                    $qualityCounts[$picked4k['quality']] = ($qualityCounts[$picked4k['quality']] ?? 0) + 1;
                }
            }

            $pickedNon4k = $this->pickBestNon4kVariant($entries);
            if ($pickedNon4k !== null && ! isset($perTitlePicked[$pickedNon4k['channel']->id])) {
                $perTitlePicked[$pickedNon4k['channel']->id] = true;
                $selectedIds[] = $pickedNon4k['channel']->id;
                $qualityCounts[$pickedNon4k['quality']] = ($qualityCounts[$pickedNon4k['quality']] ?? 0) + 1;
            }

            if ($perTitlePicked === []) {
                $context->info("TMDB {$tmdbId} group {$groupKey}: only 4K variants found and Enable 4K is off.");
            }
        }

        $selectedIds = array_values(array_unique($selectedIds));

        return [$selectedIds, $qualityCounts];
    }

    /**
     * @param array<int, array{channel: Channel, quality: string, bitrate: ?int}> $entries
     * @return array{channel: Channel, quality: string, bitrate: ?int}|null
     */
    private function pickBestByQuality(array $entries, string $quality): ?array
    {
        $matches = array_values(array_filter(
            $entries,
            fn (array $entry) => $entry['quality'] === $quality
        ));

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (array $a, array $b) => ($b['bitrate'] ?? -1) <=> ($a['bitrate'] ?? -1));

        return $matches[0];
    }

    private function buildSelectionGroupKey(Channel $channel, int $tmdbId): string
    {
        $label = (string) ($channel->title_custom ?: $channel->title ?: $channel->name_custom ?: $channel->name ?: '');
        $normalized = mb_strtolower(trim($label));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        // Keep episodic entries separate so series episodes are not collapsed into a single pick.
        $isEpisodeLike = preg_match('/\bs\d{1,2}e\d{1,2}\b|\b\d{1,2}x\d{1,2}\b|season\s*\d+\s*episode\s*\d+/i', $normalized) === 1;

        if ($isEpisodeLike) {
            return $tmdbId . '|' . $normalized;
        }

        return (string) $tmdbId;
    }

    /**
     * @param array<int, array{channel: Channel, quality: string, bitrate: ?int}> $entries
     * @return array{channel: Channel, quality: string, bitrate: ?int}|null
     */
    private function pickBestNon4kVariant(array $entries): ?array
    {
        $non4k = array_values(array_filter(
            $entries,
            fn (array $entry) => $entry['quality'] !== '4k'
        ));

        if ($non4k === []) {
            return null;
        }

        usort($non4k, function (array $a, array $b): int {
            $rankA = $this->qualityRank($a['quality']);
            $rankB = $this->qualityRank($b['quality']);

            if ($rankA !== $rankB) {
                return $rankB <=> $rankA;
            }

            $bitrateCmp = ($b['bitrate'] ?? -1) <=> ($a['bitrate'] ?? -1);
            if ($bitrateCmp !== 0) {
                return $bitrateCmp;
            }

            return $a['channel']->id <=> $b['channel']->id;
        });

        return $non4k[0] ?? null;
    }

    private function qualityRank(string $quality): int
    {
        return match ($quality) {
            '1440p' => 6,
            '1080p' => 5,
            '720p' => 4,
            '576p' => 3,
            '480p' => 2,
            '360p', '240p' => 1,
            default => 0,
        };
    }

    private function detectChannelQuality(Channel $channel, bool $probeMissing, bool $allowSideEffects): string
    {
        $rawStats = is_array($channel->stream_stats ?? null) ? $channel->stream_stats : [];

        if ($allowSideEffects && $probeMissing && $rawStats === [] && method_exists($channel, 'ensureStreamStats')) {
            $rawStats = $channel->ensureStreamStats();
        }

        if ($rawStats === []) {
            return 'unknown';
        }

        if (class_exists(StreamStatsService::class)) {
            $stats = StreamStatsService::normalize($rawStats);
            $quality = StreamStatsService::detectQuality($stats);

            return $this->normalizeQuality($quality);
        }

        // Fallback if host version does not expose StreamStatsService.
        $emby = method_exists($channel, 'getEmbyStreamStats') ? $channel->getEmbyStreamStats() : [];
        $resolution = strtolower((string) ($emby['resolution'] ?? ''));

        if (str_contains($resolution, '3840x') || str_contains($resolution, '2160')) {
            return '4k';
        }

        if (str_contains($resolution, '1920x') || str_contains($resolution, '1080')) {
            return '1080p';
        }

        if (str_contains($resolution, '1280x') || str_contains($resolution, '720')) {
            return '720p';
        }

        return 'unknown';
    }

    private function detectChannelBitrate(Channel $channel): ?int
    {
        $rawStats = is_array($channel->stream_stats ?? null) ? $channel->stream_stats : [];
        if ($rawStats === []) {
            return null;
        }

        if (! class_exists(StreamStatsService::class)) {
            return null;
        }

        $stats = StreamStatsService::normalize($rawStats);
        foreach (['bitrate', 'bit_rate'] as $key) {
            $value = $stats[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function resolveSeriesTmdbId(Series $series): ?int
    {
        $direct = $series->tmdb_id ?? null;
        if (is_numeric($direct) && (int) $direct > 0) {
            return (int) $direct;
        }

        if (method_exists($series, 'getMovieDbIds')) {
            $ids = $series->getMovieDbIds();
            if (is_array($ids)) {
                foreach (['tvdb', 'tmdb', 'id'] as $key) {
                    $value = $ids[$key] ?? null;
                    if (is_numeric($value) && (int) $value > 0) {
                        return (int) $value;
                    }
                }

                foreach ($ids as $value) {
                    if (is_numeric($value) && (int) $value > 0) {
                        return (int) $value;
                    }
                }
            }
        }

        $raw = $series->metadata ?? null;
        $meta = is_array($raw) ? $raw : [];

        foreach (['tmdb_id', 'tmdb', 'id'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @return array<string>
     */
    private function parseQualityList(string $csv): array
    {
        $items = array_map('trim', explode(',', $csv));
        $items = array_filter($items, fn (string $q) => $q !== '');
        $items = array_map(fn (string $q) => $this->normalizeQuality($q), $items);

        return array_values(array_unique(array_filter($items, fn (string $q) => $q !== '')));
    }

    private function normalizeQuality(string $quality): string
    {
        $q = strtolower(trim($quality));
        if ($q === '') {
            return 'unknown';
        }

        if (in_array($q, ['2160', '4k', '2160p', 'uhd'], true)) {
            return '4k';
        }

        if ($q === '1440') {
            return '1440p';
        }

        if ($q === '1080') {
            return '1080p';
        }

        if ($q === '720') {
            return '720p';
        }

        if ($q === '576') {
            return '576p';
        }

        if ($q === '480') {
            return '480p';
        }

        if ($q === '360') {
            return '360p';
        }

        if ($q === '240') {
            return '240p';
        }

        return match ($q) {
            '1080p', '720p', '576p', '480p', '360p', '240p', '1440p' => $q,
            default => $q,
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveMediaTypes(array $settings): string
    {
        // Backward compatibility for existing saved dropdown setting.
        if (isset($settings['media_types']) && is_string($settings['media_types'])) {
            $legacy = (string) $settings['media_types'];
            if (in_array($legacy, ['both', 'movie', 'tv'], true)) {
                return $legacy;
            }
        }

        $includeMovies = (bool) ($settings['include_movies'] ?? true);
        $includeSeries = (bool) ($settings['include_series'] ?? true);

        if ($includeMovies && $includeSeries) {
            return 'both';
        }

        if ($includeMovies) {
            return 'movie';
        }

        if ($includeSeries) {
            return 'tv';
        }

        // Safe fallback: if nothing selected, keep behavior broad.
        return 'both';
    }

    /**
     * Fetch Seerr TMDB IDs.
     *
     * @return array{
     *   ids: array<int>,
    *   items: array<int, array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>}>,
     *   by_type: array{
    *     movie: array{ids: array<int>, items: array<int, array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>}>},
    *     tv: array{ids: array<int>, items: array<int, array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>}>}
     *   }
     * }|null
     */
    private function fetchSeerrCatalog(
        string $seerrUrl,
        string $apiKey,
        string $mediaTypes,
        PluginExecutionContext $context,
    ): ?array {
        $types = $mediaTypes === 'both' ? ['movie', 'tv'] : [$mediaTypes];
        $tmdbIds = [];
        $itemsByTmdbId = [];
        $byType = [
            'movie' => ['ids' => [], 'items' => []],
            'tv' => ['ids' => [], 'items' => []],
        ];
        $typeCount = count($types);
        $typeIndex = 0;

        foreach ($types as $type) {
            $typeIndex++;

            if ($context->cancellationRequested()) {
                return [
                    'ids' => array_values(array_unique($tmdbIds)),
                    'items' => array_values($itemsByTmdbId),
                    'by_type' => $byType,
                ];
            }

            $context->heartbeat(
                "Fetching {$type} requests ({$typeIndex}/{$typeCount})...",
                progress: 10 + (int) ($typeIndex / max($typeCount, 1) * 15),
            );

            $pageResult = $this->paginateRequests($seerrUrl, $apiKey, $type, $context);
            if ($pageResult === null) {
                return null;
            }

            $tmdbIds = [...$tmdbIds, ...$pageResult['ids']];
            $byType[$type]['ids'] = array_values(array_unique(array_merge($byType[$type]['ids'], $pageResult['ids'])));

            foreach ($pageResult['items'] as $item) {
                $itemTmdbId = (int) ($item['tmdb_id'] ?? 0);
                if ($itemTmdbId > 0 && ! isset($itemsByTmdbId[$itemTmdbId])) {
                    $itemsByTmdbId[$itemTmdbId] = $item;
                }
                if ($itemTmdbId > 0) {
                    $byType[$type]['items'][$itemTmdbId] = $item;
                }
            }
        }

        $byType['movie']['items'] = array_values($byType['movie']['items']);
        $byType['tv']['items'] = array_values($byType['tv']['items']);

        return [
            'ids' => array_values(array_unique($tmdbIds)),
            'items' => array_values($itemsByTmdbId),
            'by_type' => $byType,
        ];
    }

    /**
     * Probe matched variants per TMDB ID before quality selection.
     *
     * @param array<Channel> $matchedChannels
     * @return array{attempted: int, succeeded: int, no_data: int, failures: int, by_tmdb: array<string, array{attempted: int, succeeded: int, no_data: int, failures: int, total_variants: int}>}
     */
    private function probeMatchedChannelsByTmdb(
        array $matchedChannels,
        bool $probeMissing,
        int $probeTimeout,
        PluginExecutionContext $context,
        bool $persistProbeResults,
    ): array
    {
        if (! $probeMissing) {
            return [
                'attempted' => 0,
                'succeeded' => 0,
                'no_data' => 0,
                'failures' => 0,
                'by_tmdb' => [],
            ];
        }

        $grouped = [];
        foreach ($matchedChannels as $channel) {
            $tmdbId = $channel->getTmdbId();
            if ($tmdbId === null) {
                continue;
            }

            $grouped[$tmdbId][] = $channel;
        }

        $report = [
            'attempted' => 0,
            'succeeded' => 0,
            'no_data' => 0,
            'failures' => 0,
            'by_tmdb' => [],
        ];

        $groupCount = count($grouped);
        $groupIndex = 0;
        foreach ($grouped as $tmdbId => $variants) {
            $groupIndex++;

            if ($context->cancellationRequested()) {
                return $report;
            }

            if ($groupIndex % 20 === 0) {
                $context->heartbeat("Probing TMDB groups {$groupIndex}/{$groupCount}...", progress: 72);
            }

            $context->info("TMDB {$tmdbId} has " . count($variants) . ' variants, probing all variants...');
            $groupAttempted = 0;
            $groupProbed = 0;
            $groupFailures = 0;
            $groupNoData = 0;

            foreach ($variants as $variantIndex => $channel) {
                if ($context->cancellationRequested()) {
                    return $report;
                }

                if ($variantIndex % 10 === 0) {
                    $context->heartbeat(
                        "Probing TMDB {$tmdbId} variants " . ($variantIndex + 1) . '/' . count($variants) . '...',
                        progress: 74,
                    );
                }

                if (! method_exists($channel, 'probeStreamStats') && ! method_exists($channel, 'ensureStreamStats')) {
                    continue;
                }

                $groupAttempted++;
                $report['attempted']++;

                try {
                    if (method_exists($channel, 'probeStreamStats')) {
                        $stats = $channel->probeStreamStats($probeTimeout);

                        if (
                            $persistProbeResults
                            && is_array($stats)
                            && $stats !== []
                            && method_exists($channel, 'updateQuietly')
                        ) {
                            $channel->updateQuietly([
                                'stream_stats' => $stats,
                                'stream_stats_probed_at' => now(),
                            ]);
                        }
                    } else {
                        $stats = $channel->ensureStreamStats();
                    }

                    if (is_array($stats) && $stats !== []) {
                        // Keep the probed stats on the in-memory model for immediate quality evaluation.
                        $channel->stream_stats = $stats;
                        $groupProbed++;
                        $report['succeeded']++;
                    } else {
                        $groupNoData++;
                        $report['no_data']++;
                    }
                } catch (\Throwable $e) {
                    $groupFailures++;
                    $report['failures']++;
                    $context->warning("Probe failed for channel {$channel->id}: {$e->getMessage()}");
                }
            }

            $report['by_tmdb'][(string) $tmdbId] = [
                'attempted' => $groupAttempted,
                'succeeded' => $groupProbed,
                'no_data' => $groupNoData,
                'failures' => $groupFailures,
                'total_variants' => count($variants),
            ];

            $context->info(
                "TMDB {$tmdbId}: attempted {$groupAttempted}/" . count($variants)
                . " probe(s), succeeded {$groupProbed}, no-data {$groupNoData} (failures: {$groupFailures}) before quality selection."
            );
        }

        return $report;
    }

    /**
     * Validates connectivity and API key by calling /request/count.
     */
    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $seerrUrl = rtrim((string) ($settings['seerr_url'] ?? ''), '/');
        $apiKey   = (string) ($settings['seerr_api_key'] ?? '');

        if ($seerrUrl === '') {
            return PluginActionResult::failure('Seerr URL is not configured.');
        }

        if ($apiKey === '') {
            return PluginActionResult::failure('API key is not configured.');
        }

        $context->info("Testing connection to {$seerrUrl}...");

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->timeout(10)
                ->get("{$seerrUrl}/api/v1/request/count");

            if ($response->successful()) {
                $data = $response->json();

                return PluginActionResult::success(
                    "Connected to Seerr at {$seerrUrl}.",
                    [
                        'url'         => $seerrUrl,
                        'http_status' => $response->status(),
                        'total'       => $data['total'] ?? '?',
                        'available'   => $data['available'] ?? '?',
                        'pending'     => $data['pending'] ?? '?',
                        'approved'    => $data['approved'] ?? '?',
                    ],
                );
            }

            $hint = $response->status() === 401 ? ' (invalid API key)' : '';

            return PluginActionResult::failure("Seerr returned HTTP {$response->status()}{$hint}.", [
                'url'         => $seerrUrl,
                'http_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            return PluginActionResult::failure("Connection failed: {$e->getMessage()}", ['url' => $seerrUrl]);
        }
    }

    /**
     * Page through /api/v1/request for a single media type and collect unique tmdbIds.
     * Uses the API's mediaType query param for server-side filtering.
     * Returns null on a first-page connection failure.
     *
    * @return array{ids: array<int>, items: array<int, array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>, availability_status: int}>}|null
     */
    private function paginateRequests(
        string $seerrUrl,
        string $apiKey,
        string $mediaType,
        PluginExecutionContext $context,
    ): ?array {
        $tmdbIds    = [];
        $items      = [];
        $seen       = [];
        $take       = 100;
        $skip       = 0;
        $totalPages = 1;
        $page       = 1;

        do {
            if ($context->cancellationRequested()) {
                return [
                    'ids' => $tmdbIds,
                    'items' => $items,
                ];
            }

            try {
                $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                    ->timeout(15)
                    ->get("{$seerrUrl}/api/v1/request", [
                        'take'      => $take,
                        'skip'      => $skip,
                        'filter'    => 'all',
                        'sort'      => 'added',
                        'mediaType' => $mediaType,
                    ]);
            } catch (\Throwable $e) {
                if ($skip === 0) {
                    $context->error("Seerr connection failed: {$e->getMessage()}");
                    return null;
                }
                $context->warning("Seerr request failed on page {$page}: {$e->getMessage()}");
                break;
            }

            if (! $response->successful()) {
                if ($skip === 0) {
                    $context->error("Seerr returned HTTP {$response->status()} on first {$mediaType} request page.");
                    return null;
                }
                $context->warning("Seerr returned HTTP {$response->status()} on page {$page} — stopping pagination.");
                break;
            }

            $data       = $response->json();
            $results    = $data['results'] ?? [];
            $totalPages = max(1, (int) ($data['pageInfo']['pages'] ?? 1));

            foreach ($results as $index => $request) {
                if ($index % 25 === 0 && $context->cancellationRequested()) {
                    return [
                        'ids' => $tmdbIds,
                        'items' => $items,
                    ];
                }

                $tmdbId = (int) ($request['media']['tmdbId'] ?? 0);

                if ($tmdbId === 0) {
                    continue;
                }

                if (! isset($seen[$tmdbId])) {
                    $title = $this->extractSeerrRequestTitle($request);
                    if ($title === 'unknown') {
                        $resolvedTitle = $this->resolveSeerrMediaTitle($seerrUrl, $apiKey, $mediaType, $tmdbId, $context);
                        if ($resolvedTitle !== null && $resolvedTitle !== '') {
                            $title = $resolvedTitle;
                        }
                    }

                    $seen[$tmdbId] = true;
                    $tmdbIds[]     = $tmdbId;
                    $items[$tmdbId] = [
                        'tmdb_id' => $tmdbId,
                        'title' => $title,
                        'media_type' => (string) ($request['media']['mediaType'] ?? $mediaType),
                        'requested_seasons' => $this->extractSeerrRequestedSeasons($request),
                        'availability_status' => (int) ($request['media']['status'] ?? 0),
                    ];
                    continue;
                }

                $items[$tmdbId] = $this->mergeSeerrRequestedItem($items[$tmdbId], [
                    'tmdb_id' => $tmdbId,
                    'title' => $this->extractSeerrRequestTitle($request),
                    'media_type' => (string) ($request['media']['mediaType'] ?? $mediaType),
                    'requested_seasons' => $this->extractSeerrRequestedSeasons($request),
                    'availability_status' => (int) ($request['media']['status'] ?? 0),
                ]);
            }

            $progress = (int) ($page / $totalPages * 25);
            $context->heartbeat("Fetched {$mediaType} page {$page}/{$totalPages}...", progress: $progress);

            $skip += $take;
            $page++;
        } while ($page <= $totalPages && count($results) === $take);

        return [
            'ids' => $tmdbIds,
            'items' => array_values($items),
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function extractSeerrRequestTitle(array $request): string
    {
        $media = is_array($request['media'] ?? null) ? $request['media'] : [];

        foreach (['title', 'name', 'originalTitle', 'originalName'] as $key) {
            $value = trim((string) ($media[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['subject', 'message'] as $key) {
            $value = trim((string) ($request[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<int>
     */
    private function extractSeerrRequestedSeasons(array $request): array
    {
        $media = is_array($request['media'] ?? null) ? $request['media'] : [];
        $sources = [];

        // Seerr TV requests expose requested seasons at results[].seasons[].seasonNumber.
        foreach ([$request['seasons'] ?? null, $request['requestedSeasons'] ?? null, $media['seasons'] ?? null, $media['requestedSeasons'] ?? null] as $candidate) {
            if (is_array($candidate)) {
                $sources[] = $candidate;
            }
        }

        $seasonNumbers = [];

        foreach ($sources as $seasons) {
            foreach ($seasons as $season) {
                if (is_numeric($season)) {
                    $seasonNumber = (int) $season;
                } elseif (is_array($season)) {
                    $seasonNumber = null;
                    foreach (['seasonNumber', 'season_number', 'number'] as $key) {
                        $value = $season[$key] ?? null;
                        if (is_numeric($value)) {
                            $seasonNumber = (int) $value;
                            break;
                        }
                    }
                } else {
                    $seasonNumber = null;
                }

                if (($seasonNumber ?? 0) > 0) {
                    $seasonNumbers[$seasonNumber] = true;
                }
            }
        }

        $seasonNumbers = array_map('intval', array_keys($seasonNumbers));
        sort($seasonNumbers);

        return $seasonNumbers;
    }

    /**
    * @param array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>, availability_status?: int} $existing
    * @param array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>, availability_status?: int} $incoming
    * @return array{tmdb_id: int, title: string, media_type: string, requested_seasons: array<int>, availability_status?: int}
     */
    private function mergeSeerrRequestedItem(array $existing, array $incoming): array
    {
        $mergedSeasons = array_values(array_unique(array_merge(
            $existing['requested_seasons'] ?? [],
            $incoming['requested_seasons'] ?? [],
        )));
        sort($mergedSeasons);

        if (($existing['title'] ?? 'unknown') === 'unknown' && ($incoming['title'] ?? 'unknown') !== 'unknown') {
            $existing['title'] = $incoming['title'];
        }

        $existingStatus = (int) ($existing['availability_status'] ?? 0);
        $incomingStatus = (int) ($incoming['availability_status'] ?? 0);
        if ($existingStatus <= 0) {
            $existing['availability_status'] = $incomingStatus;
        } elseif ($incomingStatus > 0) {
            // Keep the least-available status across duplicate requests for the same TMDB ID.
            $existing['availability_status'] = min($existingStatus, $incomingStatus);
        }

        $existing['requested_seasons'] = $mergedSeasons;

        return $existing;
    }

    private function resolveSeerrMediaTitle(
        string $seerrUrl,
        string $apiKey,
        string $mediaType,
        int $tmdbId,
        PluginExecutionContext $context,
    ): ?string {
        $endpoint = $mediaType === 'tv'
            ? "{$seerrUrl}/api/v1/tv/{$tmdbId}"
            : "{$seerrUrl}/api/v1/movie/{$tmdbId}";

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->timeout(10)
                ->get($endpoint);
        } catch (\Throwable $e) {
            $context->warning("Failed to resolve Seerr {$mediaType} title for TMDB {$tmdbId}: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        $title = $mediaType === 'tv'
            ? (string) ($payload['name'] ?? $payload['originalName'] ?? '')
            : (string) ($payload['title'] ?? $payload['originalTitle'] ?? '');

        $title = trim($title);

        return $title !== '' ? $title : null;
    }
}
