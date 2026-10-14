<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Packs;

use OCP\Files\SimpleFS\ISimpleFolder;

/** Collects a pack's stickers while it is built and writes their files. */
class Pack {
	/** @var array<string, array{id: string, file: string, name: string, category: string, keywords: list<string>}> */
	private array $stickers = [];

	public function __construct(
		private array $spec,
		private ISimpleFolder $folder,
	) {
	}

	public function count(): int {
		return count($this->stickers);
	}

	/** @param iterable<string> $keywords */
	public function add(string $id, string $extension, string $data, string $name, string $category, iterable $keywords = []): void {
		if (isset($this->stickers[$id]) || !Emoji::looksLikeImage($data, $extension)) {
			return;
		}
		if ($extension === 'svg' && !Emoji::svgIsSafe($data)) {
			return;
		}
		$file = $id . '.' . $extension;
		$this->folder->newFile($file, $data);
		$words = [];
		foreach ($keywords as $word) {
			$word = mb_strtolower(trim((string)$word));
			if ($word !== '' && $word !== mb_strtolower($name)) {
				$words[$word] = true;
			}
		}
		$words = array_keys($words);
		sort($words);
		$this->stickers[$id] = ['id' => $id, 'file' => $file, 'name' => $name, 'category' => $category, 'keywords' => $words];
	}

	/** The pack's entry of index.json: stickers in Unicode's order, extras last. */
	public function index(array $emoji): array {
		$stickers = array_values($this->stickers);
		usort($stickers, fn (array $a, array $b) => [$emoji[$a['id']]['order'] ?? 1_000_000, $a['name']] <=> [$emoji[$b['id']]['order'] ?? 1_000_000, $b['name']]);
		$categories = [];
		foreach ($stickers as $sticker) {
			if (!in_array($sticker['category'], $categories, true)) {
				$categories[] = $sticker['category'];
			}
		}
		return [
			'id' => $this->spec['id'],
			'title' => $this->spec['title'],
			'license' => $this->spec['license'],
			'licenseUrl' => $this->spec['licenseUrl'],
			'source' => $this->spec['source'],
			'attribution' => $this->spec['attribution'],
			'categories' => $categories,
			// Short keys: the catalogue is loaded by every picker.
			'stickers' => array_map(fn (array $s) => [
				'i' => $s['id'], 'f' => $s['file'], 'n' => $s['name'],
				'c' => array_search($s['category'], $categories, true), 'k' => implode(' ', $s['keywords']),
			], $stickers),
		];
	}
}
