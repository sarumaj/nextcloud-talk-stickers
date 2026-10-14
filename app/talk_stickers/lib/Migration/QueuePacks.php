<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Migration;

use OCA\TalkStickers\BackgroundJob\BuildPacks;
use OCA\TalkStickers\Packs\Builder;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** After installation and updates: the packs are built in the background when they are missing or old. */
class QueuePacks implements IRepairStep {
	public function __construct(
		private Builder $builder,
		private IJobList $jobList,
	) {
	}

	public function getName(): string {
		return 'Queue the build of the sticker packs';
	}

	public function run(IOutput $output): void {
		if (!$this->builder->upToDate() && !$this->jobList->has(BuildPacks::class, null)) {
			$this->jobList->add(BuildPacks::class);
			$output->info('The sticker packs are built by a background job (or now: occ talk_stickers:packs).');
		}
	}
}
