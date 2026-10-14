<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Reference;

use OCA\TalkStickers\AppInfo\Application;
use OCA\TalkStickers\Service\StickerService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * The Smart Picker's "Stickers" (its custom element is in js/) and the preview
 * of a sent sticker: a link to /apps/talk_stickers/s/<pack>/<id> is shown as
 * the sticker's image (rich object talk_stickers_sticker; clients without the
 * widget show the Open Graph image).
 */
class StickerReferenceProvider extends ADiscoverableReferenceProvider {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private StickerService $stickers,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		return $this->l10n->t('Stickers');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function matchReference(string $referenceText): bool {
		return $this->parse($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$parsed = $this->parse($referenceText);
		if ($parsed === null) {
			return null;
		}
		[$pack, $id] = $parsed;
		$sticker = $this->stickers->find($pack, $id, $this->userId);
		if ($sticker === null) {
			return null;
		}
		$reference = new Reference($referenceText);
		$reference->setTitle($sticker['name']);
		$reference->setDescription($sticker['pack']);
		$reference->setImageUrl($sticker['image']);
		$reference->setUrl($referenceText);
		$reference->setRichObject(Application::RICH_OBJECT_TYPE, [
			'name' => $sticker['name'],
			'pack' => $sticker['pack'],
			'image' => $sticker['image'],
			'link' => $referenceText,
		]);
		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	/** Shipped stickers look the same to everyone; the shared folder's only to those who can read it. */
	public function getCacheKey(string $referenceId): ?string {
		return ($this->parse($referenceId)[0] ?? null) === StickerService::FAMILY ? $this->userId : null;
	}

	/** @return array{0: string, 1: string}|null The pack and id of a sticker's link. */
	private function parse(string $text): ?array {
		$template = $this->stickers->link(StickerService::LINK_PACK, StickerService::LINK_ID);
		$pattern = '#^' . str_replace(
			[preg_quote(StickerService::LINK_PACK, '#'), preg_quote(StickerService::LINK_ID, '#')],
			['([a-z0-9-]+)', '([A-Za-z0-9-]+)'],
			preg_quote($template, '#'),
		) . '$#';
		// With or without index.php: both reach the route.
		$pattern = str_replace(preg_quote('/index.php', '#'), '(?:/index\.php)?', $pattern);
		return preg_match($pattern, trim($text), $match) === 1 ? [$match[1], $match[2]] : null;
	}
}
