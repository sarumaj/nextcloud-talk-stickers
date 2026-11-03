<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// For the tests: files_sharing's event (Nextcloud 33), which nextcloud/ocp does not contain.

namespace OCA\Files_Sharing\Event;

use OCP\EventDispatcher\Event;
use OCP\IUser;

class UserShareAccessUpdatedEvent extends Event {
	/** @param list<IUser> $users */
	public function __construct(
		private array $users,
	) {
		parent::__construct();
	}

	/** @return list<IUser> */
	public function getUsers(): array {
		return $this->users;
	}
}
