<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Listener;

use OCA\TalkStickers\AppInfo\Application;
use OCA\TalkStickers\Service\StickerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * /sticker in Talk, from any client (the mobile apps have no Smart Picker):
 * before a message "/sticker <words or emoji>" is saved, it becomes the link of
 * the best-fitting sticker, sent by its author like one from the picker; a bare
 * "/sticker" becomes the link of the stickers' page. A message whose words fit no
 * sticker stays as typed. Listens to Talk's OCA\Talk\Events\BeforeChatMessageSentEvent
 * (by name: Talk is not needed to install the app).
 *
 * @template-implements IEventListener<Event>
 */
class StickerCommandListener implements IEventListener {
	/** The command's names: English, German, Polish. */
	private const COMMAND = '/^\/(?:stickers?|naklejk[ai])(?:\s+(.*))?$/isu';

	public function __construct(
		private StickerService $stickers,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getComment')) {
			return;
		}
		$comment = $event->getComment();
		if ($comment->getActorType() !== 'users'
			|| preg_match(self::COMMAND, trim($comment->getMessage()), $match) !== 1) {
			return;
		}
		$query = trim($match[1] ?? '');
		if ($query === '') {
			$page = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');
			$comment->setMessage($this->l10n->t('Stickers: %s', [$page]));
			return;
		}
		$link = $this->stickers->search($query, $comment->getActorId());
		if ($link !== null) {
			$comment->setMessage($link);
		}
	}
}
