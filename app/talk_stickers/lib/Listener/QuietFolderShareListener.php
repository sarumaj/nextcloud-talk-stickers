<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Keeps Talk from announcing the one share this app creates: when /sticker or
 * /gif is the first file of its author in a conversation, the author's folder
 * of the conversation is shared with it (by Talk's own ConversationFolderService,
 * as for an attachment), and Talk would post "shared a folder" in the chat. This
 * listener runs before Talk's and stops ShareCreatedEvent (after the share is
 * made) and Talk's BeforeDuplicateShareSentEvent, only for the conversation
 * StickerCommandListener names while it creates that share.
 *
 * Stopping ShareCreatedEvent also stops files_sharing's listener, which mounts
 * the new share for its recipients: there Talk names the conversation's folder
 * from each recipient's side ("Ben Example-<token>" for Anna, where the sender
 * Ben has "Anna Example-<token>"). Without it the share is mounted later under
 * the sender's name of the folder, which does not exist for the recipient, and
 * the recipient's Talk shows the sticker as a gray placeholder. So this listener
 * asks files_sharing to update the recipients' shares itself
 * (UserShareAccessUpdatedEvent, Nextcloud 33 and later).
 *
 * @template-implements IEventListener<Event>
 */
class QuietFolderShareListener implements IEventListener {
	/** The conversation's token while its folder share is created, else null. */
	public static ?string $quietFor = null;

	/** files_sharing's event that updates the mounts of users' received shares. */
	public const SHARE_ACCESS_UPDATED_EVENT = 'OCA\\Files_Sharing\\Event\\UserShareAccessUpdatedEvent';

	public function __construct(
		private IEventDispatcher $dispatcher,
		private IShareManager $shareManager,
	) {
	}

	public function handle(Event $event): void {
		if (self::$quietFor === null || !method_exists($event, 'getShare')) {
			return;
		}
		$share = $event->getShare();
		if ($share instanceof IShare && $share->getShareType() === IShare::TYPE_ROOM && $share->getSharedWith() === self::$quietFor) {
			$event->stopPropagation();
			if ($event instanceof ShareCreatedEvent) {
				$this->updateRecipients($share);
			}
		}
	}

	/** What files_sharing would have done on ShareCreatedEvent: mount the share for its recipients. */
	private function updateRecipients(IShare $share): void {
		$class = self::SHARE_ACCESS_UPDATED_EVENT;
		if (!class_exists($class) || !method_exists($this->shareManager, 'getUsersForShare')) {
			return;
		}
		$users = [];
		foreach ($this->shareManager->getUsersForShare($share) as $user) {
			if ($user->getUID() !== $share->getShareOwner() && $user->getUID() !== $share->getSharedBy()) {
				$users[] = $user;
			}
		}
		if ($users !== []) {
			$this->dispatcher->dispatchTyped(new $class($users));
		}
	}
}
