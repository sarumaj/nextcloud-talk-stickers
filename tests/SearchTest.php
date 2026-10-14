<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Tests;

use OCA\TalkStickers\Service\GifService;
use OCA\TalkStickers\Service\StickerService;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase {
	public function testEmojiIds(): void {
		$this->assertSame('1f389', StickerService::emojiId('🎉'));
		$this->assertSame('2764', StickerService::emojiId('❤️'));
		$this->assertSame('1f44d-1f3fd', StickerService::emojiId('👍🏽'));
		$this->assertNull(StickerService::emojiId('party'));
		$this->assertNull(StickerService::emojiId('happy birthday!'));
	}

	public function testScores(): void {
		$exact = StickerService::score(['grinning', 'face'], 'grinning face', '');
		$partial = StickerService::score(['grinning', 'face'], 'grinning squinting face', '');
		$this->assertGreaterThan($partial, $exact);
		$this->assertGreaterThan(
			StickerService::score(['party'], 'party popper with confetti', ''),
			StickerService::score(['party'], 'party popper', ''),
		);
		$this->assertGreaterThan(0, StickerService::score(['thumbs', 'up'], 'thumbs up: light skin tone', ''));
		$this->assertGreaterThan(0, StickerService::score(['tada'], 'party popper', 'celebration party popper tada'));
		$this->assertSame(0, StickerService::score(['xyzzy'], 'party popper', 'tada'));
	}

	public function testGiphyLinks(): void {
		$this->assertSame('BaDsH4FpMBnqdK8J0g', GifService::idOfLink('https://giphy.com/gifs/seal-sappy-seals-BaDsH4FpMBnqdK8J0g'));
		$this->assertSame('nc42', GifService::idOfLink('https://giphy.com/gifs/nc42'));
		$this->assertSame('BaDsH4FpMBnqdK8J0g', GifService::idOfLink('https://media4.giphy.com/media/BaDsH4FpMBnqdK8J0g/giphy.gif?cid=a&rid=giphy.gif&ct=g'));
		$this->assertSame('CKqTAwtMSLl0lz4cUM', GifService::idOfLink('https://media1.giphy.com/media/v1.Y2lk/CKqTAwtMSLl0lz4cUM/200w.gif'));
		$this->assertNull(GifService::idOfLink('look https://giphy.com/gifs/x-abc'));
		$this->assertNull(GifService::idOfLink('https://example.com/gifs/x-abc'));
	}

	public function testManifestIsComplete(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../app/talk_stickers/lib/Packs/manifest.json'), true);
		foreach ($manifest['packs'] as $pack) {
			foreach (['id', 'kind', 'title', 'license', 'licenseUrl', 'source', 'attribution'] as $key) {
				$this->assertNotEmpty($pack[$key] ?? null, $pack['id'] . ': ' . $key);
			}
			$this->assertContains($pack['kind'], ['fluent', 'noto-animated', 'noto', 'openmoji', 'twemoji']);
			foreach (['archive', 'metadata'] as $pinned) {
				if (isset($pack[$pinned])) {
					$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pack[$pinned]['sha256']);
				}
			}
		}
	}
}
