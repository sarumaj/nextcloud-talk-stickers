<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Service;

use OCA\TalkStickers\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The stickers: the shipped packs (packs/index.json beside the app, built by
 * packs/build.py of the repository) and the family's own, images in subfolders
 * of a shared folder ("Stickers" unless the app setting "folder" says
 * otherwise), one pack per subfolder.
 */
class StickerService {
	/** The pack id of all stickers from the shared folder; their id is the file's. */
	public const FAMILY = 'family';
	/** Images the family may add: no SVG, which could carry scripts. */
	private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
	public const LINK_PACK = 'xpackx';
	public const LINK_ID = 'XIDX';

	private ?array $index = null;

	public function __construct(
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
	) {
	}

	/** The catalogue of the picker: shipped packs, then the user's own. */
	public function catalogue(?string $userId): array {
		return [
			'imageBase' => $this->shippedBase(),
			// The picker fills in pack and id (placeholders the route's requirements allow).
			'linkBase' => $this->link(self::LINK_PACK, self::LINK_ID),
			'folder' => $this->folderName(),
			'packs' => $this->shipped()['packs'] ?? [],
			'family' => $userId === null ? [] : $this->familyPacks($userId),
		];
	}

	/**
	 * A sticker by the pack and id of its link, or null.
	 *
	 * @return array{name: string, pack: string, image: string}|null
	 */
	public function find(string $pack, string $id, ?string $userId): ?array {
		if ($pack === self::FAMILY) {
			$file = $userId === null ? null : $this->familyFile($userId, $id);
			return $file === null ? null : [
				'name' => pathinfo($file->getName(), PATHINFO_FILENAME),
				'pack' => $file->getParent()->getName(),
				'image' => $this->link(self::FAMILY, $id),
			];
		}
		foreach ($this->shipped()['packs'] ?? [] as $shipped) {
			if ($shipped['id'] !== $pack) {
				continue;
			}
			foreach ($shipped['stickers'] as $sticker) {
				if ($sticker['i'] === $id) {
					return [
						'name' => $sticker['n'],
						'pack' => $shipped['title'],
						'image' => $this->shippedBase() . $pack . '/' . $sticker['f'],
					];
				}
			}
		}
		return null;
	}

	/**
	 * The link of the sticker that best fits the words of /sticker, or null:
	 * an emoji (its sticker), or words matched against names and keywords. A first
	 * word naming a pack (its id, title or a word of it, "ours" for the shared
	 * folder) keeps to that pack. Ties go to the shared folder, then to the app
	 * setting "command_pack" (noto-animated), then to the order of the packs.
	 */
	public function search(string $query, ?string $userId): ?string {
		$words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if ($words === []) {
			return null;
		}
		$packs = $this->searchablePacks($userId);
		if (count($words) > 1) {
			$chosen = array_filter($packs, fn (array $pack) => in_array($words[0], $pack['names'], true));
			if ($chosen !== []) {
				$packs = $chosen;
				array_shift($words);
			}
		}
		$emoji = self::emojiId(implode('', $words));
		$best = null;
		$bestScore = 0;
		foreach ($packs as $pack) {
			foreach ($pack['stickers'] as $sticker) {
				$score = $emoji !== null
					? ($sticker['id'] === $emoji ? 1000 : 0)
					: self::score($words, mb_strtolower($sticker['name']), mb_strtolower($sticker['keywords']));
				// Strictly better only: earlier packs win ties.
				if ($score > $bestScore) {
					$best = $this->link($pack['link'], $sticker['id']);
					$bestScore = $score;
				}
			}
		}
		return $best;
	}

	/** The id an emoji's sticker has (its code points, without variation selectors), or null for words. */
	public static function emojiId(string $text): ?string {
		if ($text === '' || preg_match('/^[\p{L}\p{N}\p{P}\s]+$/u', $text) === 1) {
			return null;
		}
		$points = [];
		foreach (mb_str_split($text) as $char) {
			$point = mb_ord($char);
			if ($point !== 0xFE0F && $point !== 0xFE0E) {
				$points[] = dechex($point);
			}
		}
		return implode('-', $points);
	}

	/**
	 * How well words fit a sticker: the whole name, every word as a word of the
	 * name, as the start of one, or among the keywords; 0 when a word fits nowhere.
	 */
	public static function score(array $words, string $name, string $keywords): int {
		$query = implode(' ', $words);
		if ($name === $query) {
			return 1000;
		}
		$nameWords = preg_split('/[\s_:,-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$keywordList = preg_split('/\s+/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$score = 0;
		foreach ($words as $word) {
			if (in_array($word, $nameWords, true)) {
				$score += 10;
			} elseif (array_filter($nameWords, fn (string $w) => str_starts_with($w, $word)) !== []) {
				$score += 6;
			} elseif (in_array($word, $keywordList, true)) {
				$score += 4;
			} elseif (array_filter($keywordList, fn (string $w) => str_starts_with($w, $word)) !== []) {
				$score += 2;
			} else {
				return 0;
			}
		}
		// Of equal fits, the shorter name: "party popper" over "party popper with confetti".
		return $score * 10 - min(count($nameWords), 9);
	}

	/**
	 * The packs /sticker searches, in the order that wins ties, each with the
	 * names a first word may give it: [{names, link, stickers: [{id, name, keywords}]}].
	 */
	private function searchablePacks(?string $userId): array {
		$packs = [];
		foreach ($userId === null ? [] : $this->familyPacks($userId) as $pack) {
			$packs[] = [
				'names' => ['ours', 'own', self::FAMILY, mb_strtolower($pack['title'])],
				'link' => self::FAMILY,
				'stickers' => array_map(fn (array $s) => ['id' => $s['i'], 'name' => $s['n'], 'keywords' => ''], $pack['stickers']),
			];
		}
		$shipped = $this->shipped()['packs'] ?? [];
		$first = $this->appConfig->getValueString(Application::APP_ID, 'command_pack', 'noto-animated');
		usort($shipped, fn (array $a, array $b) => ($b['id'] === $first) <=> ($a['id'] === $first));
		foreach ($shipped as $pack) {
			$title = mb_strtolower($pack['title']);
			$packs[] = [
				'names' => array_merge([$pack['id']], explode('-', $pack['id']), [$title], preg_split('/[\s()]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: []),
				'link' => $pack['id'],
				'stickers' => array_map(fn (array $s) => ['id' => $s['i'], 'name' => $s['n'], 'keywords' => $s['k'] ?? ''], $pack['stickers']),
			];
		}
		return $packs;
	}

	/** The link a sent sticker is: it opens its image and is shown as one. */
	public function link(string $pack, string $id): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.sticker.show', ['pack' => $pack, 'id' => $id]);
	}

	/** A sticker of the shared folder the user can read, or null. */
	public function familyFile(string $userId, string $id): ?File {
		if (!ctype_digit($id)) {
			return null;
		}
		$folder = $this->familyFolder($userId);
		if ($folder === null) {
			return null;
		}
		$node = $folder->getFirstNodeById((int)$id);
		return $node instanceof File && in_array($node->getMimetype(), self::IMAGE_TYPES, true) ? $node : null;
	}

	private function shipped(): array {
		if ($this->index === null) {
			$path = $this->appManager->getAppPath(Application::APP_ID) . '/packs/index.json';
			$data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
			$this->index = is_array($data) ? $data : [];
		}
		return $this->index;
	}

	private function shippedBase(): string {
		return $this->urlGenerator->getAbsoluteURL($this->appManager->getAppWebPath(Application::APP_ID) . '/packs/');
	}

	private function folderName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'folder', 'Stickers');
	}

	private function familyFolder(string $userId): ?Folder {
		try {
			$folder = $this->rootFolder->getUserFolder($userId)->get($this->folderName());
		} catch (NotFoundException|NotPermittedException) {
			return null;
		}
		return $folder instanceof Folder ? $folder : null;
	}

	/** The shared folder's subfolders as packs (its own images as the first). */
	private function familyPacks(string $userId): array {
		$root = $this->familyFolder($userId);
		if ($root === null) {
			return [];
		}
		$packs = [];
		$folders = array_merge([$root], array_filter($root->getDirectoryListing(), fn ($node) => $node instanceof Folder));
		foreach ($folders as $folder) {
			$stickers = [];
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof File && in_array($node->getMimetype(), self::IMAGE_TYPES, true)) {
					$id = (string)$node->getId();
					$stickers[] = [
						'i' => $id,
						'n' => pathinfo($node->getName(), PATHINFO_FILENAME),
						'u' => $this->link(self::FAMILY, $id),
					];
				}
			}
			if ($stickers !== []) {
				usort($stickers, fn (array $a, array $b) => strnatcasecmp($a['n'], $b['n']));
				$packs[] = ['id' => 'family-' . $folder->getId(), 'title' => $folder->getName(), 'stickers' => $stickers];
			}
		}
		return $packs;
	}
}
