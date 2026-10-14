<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Tests;

use OCA\TalkStickers\Packs\Emoji;
use PHPUnit\Framework\TestCase;

class EmojiTest extends TestCase {
	public const EMOJI_TEST = <<<'TXT'
		# group: Smileys & Emotion
		# subgroup: face-smiling
		1F600                                                  ; fully-qualified     # 😀 E1.0 grinning face
		# group: People & Body
		1F44D                                                  ; fully-qualified     # 👍 E0.6 thumbs up
		1F44D 1F3FB                                            ; fully-qualified     # 👍🏻 E1.0 thumbs up: light skin tone
		2764 FE0F                                              ; fully-qualified     # ❤️ E0.6 red heart
		2764                                                   ; unqualified         # ❤ E0.6 red heart
		TXT;

	public function testSameIdInEveryNotation(): void {
		foreach (['2764 FE0F', '2764-fe0f', '2764_fe0f', '2764'] as $text) {
			$this->assertSame('2764', Emoji::stickerId(Emoji::parseCodepoints($text)), $text);
		}
	}

	public function testSequence(): void {
		$this->assertSame('1f469-200d-1f9b2', Emoji::stickerId(Emoji::parseCodepoints('1F469-200D-1F9B2')));
	}

	public function testSkinTone(): void {
		$this->assertTrue(Emoji::hasSkinTone(Emoji::parseCodepoints('1f44d 1f3fb')));
		$this->assertFalse(Emoji::hasSkinTone(Emoji::parseCodepoints('1f44d')));
	}

	public function testFullyQualifiedOnlyWithNamesGroupsOrder(): void {
		$emoji = Emoji::parseEmojiTest(self::EMOJI_TEST);
		$this->assertSame(['1f600', '1f44d', '1f44d-1f3fb', '2764'], array_map('strval', array_keys($emoji)));
		$this->assertSame('grinning face', $emoji['1f600']['name']);
		$this->assertSame('People & Body', $emoji['1f44d']['group']);
		$this->assertSame('red heart', $emoji['2764']['name']);
		$this->assertSame(3, $emoji['2764']['order']);
	}

	public function testImageMagic(): void {
		$this->assertTrue(Emoji::looksLikeImage("\x89PNG\r\n\x1a\nrest", 'png'));
		$this->assertTrue(Emoji::looksLikeImage("RIFF\0\0\0\0WEBPVP8 ", 'webp'));
		$this->assertFalse(Emoji::looksLikeImage('<html>error</html>', 'png'));
		$this->assertTrue(Emoji::looksLikeImage('<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg'));
	}

	public function testUnsafeSvg(): void {
		$this->assertTrue(Emoji::svgIsSafe('<svg><path d="M0 0"/><use xlink:href="#a"/></svg>'));
		foreach (['<svg><script>x</script></svg>', '<svg onload="x()"/>', '<svg><a href="javascript:x"/></svg>',
			'<svg><use xlink:href="https://x/y.svg#a"/></svg>', '<svg><foreignObject/></svg>'] as $bad) {
			$this->assertFalse(Emoji::svgIsSafe($bad), $bad);
		}
	}
}
