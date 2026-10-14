<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Listener;

use OCA\TalkStickers\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the picker and the widget wherever references are rendered.
 *
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class RenderReferenceListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'talk_stickers');
		Util::addStyle(Application::APP_ID, 'talk_stickers');
	}
}
