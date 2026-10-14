<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Packs;

/**
 * Emoji and image helpers of the pack builder, without Nextcloud: sticker ids,
 * Unicode's emoji-test.txt, and checks of downloaded images.
 *
 * A sticker's id is its emoji's code points in lower-case hex joined by "-",
 * without the variation selector FE0F, so the same emoji has the same id in
 * every pack.
 */
class Emoji {
	public const SKIN_TONES = [0x1F3FB, 0x1F3FC, 0x1F3FD, 0x1F3FE, 0x1F3FF];

	/** @param list<int> $codepoints */
	public static function stickerId(array $codepoints): string {
		return implode('-', array_map(fn (int $cp) => dechex($cp), array_values(array_filter($codepoints, fn (int $cp) => $cp !== 0xFE0F))));
	}

	/**
	 * Code points from "1F600", "1f44d 1f3fb", "1f468_200d_1f469" or "1F469-200D-1F9B2".
	 *
	 * @return list<int>
	 */
	public static function parseCodepoints(string $text): array {
		$parts = preg_split('/[\s_\-]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		return array_map(fn (string $part) => (int)hexdec($part), $parts);
	}

	/** @param list<int> $codepoints */
	public static function hasSkinTone(array $codepoints): bool {
		return array_intersect($codepoints, self::SKIN_TONES) !== [];
	}

	/**
	 * The fully-qualified emoji of emoji-test.txt by sticker id, in Unicode's order.
	 *
	 * @return array<string, array{codepoints: list<int>, name: string, group: string, order: int}>
	 */
	public static function parseEmojiTest(string $text): array {
		$emoji = [];
		$group = '';
		foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
			if (str_starts_with($line, '# group:')) {
				$group = trim(substr($line, 8));
				continue;
			}
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			[$data, $comment] = array_pad(explode('#', $line, 2), 2, '');
			[$points, $status] = array_pad(explode(';', $data, 2), 2, '');
			if (trim($status) !== 'fully-qualified') {
				continue;
			}
			$codepoints = self::parseCodepoints($points);
			// "😀 E1.0 grinning face": the name follows the emoji and its version.
			$name = preg_replace('/^\S+\s+E\d+\.\d+\s+/u', '', trim($comment)) ?? '';
			$id = self::stickerId($codepoints);
			$emoji[$id] ??= ['codepoints' => $codepoints, 'name' => $name, 'group' => $group, 'order' => count($emoji)];
		}
		return $emoji;
	}

	/** Whether data is the image its extension claims (a CDN error page is not). */
	public static function looksLikeImage(string $data, string $extension): bool {
		return match ($extension) {
			'png' => str_starts_with($data, "\x89PNG\r\n\x1a\n"),
			'webp' => substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP',
			'svg' => (bool)preg_match('/^\s*(<svg|<\?xml)/i', substr($data, 0, 512)),
			default => false,
		};
	}

	/** No scripts, event handlers or external references in an SVG. */
	public static function svgIsSafe(string $data): bool {
		return preg_match('/<script|\son\w+\s*=|javascript:|<foreignobject|xlink:href\s*=\s*["\'](?!#)/i', $data) !== 1;
	}
}
