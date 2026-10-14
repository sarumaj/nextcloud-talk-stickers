<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Command;

use OCA\TalkStickers\Packs\Builder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ talk_stickers:packs: builds the shipped packs (downloads of about 300 MB,
 * several minutes), as the background job queued on installation does.
 */
class Packs extends Command {
	public function __construct(
		private Builder $builder,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_stickers:packs')
			->setDescription('Download and build the sticker packs (about 300 MB, several minutes)')
			->addOption('if-needed', null, InputOption::VALUE_NONE, 'only when the packs in use were built from another version of the app')
			->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'only this pack (repeatable; default: the app setting "packs", or all)')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'at most this many stickers per pack (for tests)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('if-needed') && $this->builder->upToDate()) {
			$output->writeln('The packs are up to date.');
			return self::SUCCESS;
		}
		$only = $input->getOption('only') ?: null;
		$limit = $input->getOption('limit') !== null ? (int)$input->getOption('limit') : null;
		$this->builder->build($only, $limit, fn (string $line) => $output->writeln($line));
		return self::SUCCESS;
	}
}
