<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Tests;

use OCA\TalkStickers\Packs\Emoji;
use OCA\TalkStickers\Packs\Pack;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

class PackTest extends TestCase {
	public function testOrderCategoriesAndSkippedFiles(): void {
		$written = [];
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willReturnCallback(function (string $name) use (&$written) {
			$written[] = $name;
			return $this->createMock(\OCP\Files\SimpleFS\ISimpleFile::class);
		});
		$png = "\x89PNG\r\n\x1a\nrest";
		$spec = ['id' => 'test', 'title' => 'Test', 'license' => 'MIT', 'licenseUrl' => 'u', 'source' => 's', 'attribution' => 'a'];
		$pack = new Pack($spec, $folder);
		$pack->add('1f44d', 'png', $png, 'thumbs up', 'People & Body', ['Hand', 'thumbs up', '']);
		$pack->add('1f600', 'png', $png, 'grinning face', 'Smileys & Emotion');
		$pack->add('e000', 'png', $png, 'an extra', 'Extras');
		$pack->add('broken', 'png', '<html>', 'error page', 'Other');
		$pack->add('1f600', 'png', $png, 'duplicate', 'Other');
		$pack->add('bad', 'svg', '<svg onload="x()"/>', 'unsafe', 'Other');
		$index = $pack->index(Emoji::parseEmojiTest(EmojiTest::EMOJI_TEST));
		$this->assertSame(['1f600', '1f44d', 'e000'], array_column($index['stickers'], 'i'));
		$this->assertSame(['Smileys & Emotion', 'People & Body', 'Extras'], $index['categories']);
		$this->assertSame('hand', $index['stickers'][1]['k']);
		$this->assertSame(1, $index['stickers'][1]['c']);
		$this->assertSame(['1f44d.png', '1f600.png', 'e000.png'], $written);
	}
}
