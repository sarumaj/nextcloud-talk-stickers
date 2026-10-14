<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\AppInfo;

use OCA\TalkStickers\Listener\RenderReferenceListener;
use OCA\TalkStickers\Reference\StickerReferenceProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\Reference\RenderReferenceEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'talk_stickers';
	/** The rich object type of a sent sticker, rendered by the widget in js/. */
	public const RICH_OBJECT_TYPE = 'talk_stickers_sticker';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerReferenceProvider(StickerReferenceProvider::class);
		// Pages that offer the Smart Picker or show references (Talk, Text, …)
		// announce it with this event: only then are the picker and widget loaded.
		$context->registerEventListener(RenderReferenceEvent::class, RenderReferenceListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
