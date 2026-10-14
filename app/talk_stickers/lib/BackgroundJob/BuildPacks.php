<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\BackgroundJob;

use OCA\TalkStickers\Packs\Builder;
use OCA\TalkStickers\Packs\BuildRunningException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Builds the shipped packs once, after installation or an update that changes
 * their sources (Migration\QueuePacks). Takes minutes: with background jobs run
 * by cron, as Nextcloud recommends; occ talk_stickers:packs does the same at once.
 */
class BuildPacks extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Builder $builder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if ($this->builder->upToDate()) {
			return;
		}
		try {
			$this->builder->build(null, null, fn (string $line) => $this->logger->info('Sticker packs: ' . $line));
		} catch (BuildRunningException) {
			// occ talk_stickers:packs is building them.
		} catch (\Throwable $e) {
			$this->logger->error('The sticker packs could not be built; occ talk_stickers:packs tries again', ['exception' => $e]);
		}
	}
}
