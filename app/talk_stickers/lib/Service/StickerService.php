<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Service;

use OCA\TalkStickers\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The stickers: the shipped packs (built into the app data by Packs\Builder,
 * occ talk_stickers:packs) and the family's own, images in subfolders
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
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
		private IAppDataFactory $appDataFactory,
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
	 * The sticker that best fits the words of /sticker, as [pack, id], or null:
	 * "<pack>/<id>" itself (what the picker inserts in Talk),
	 * an emoji (its sticker), or words matched against names and keywords. A first
	 * word naming a pack (its id, title or a word of it, "ours" for the shared
	 * folder) keeps to that pack. Ties go to the shared folder, then to the app
	 * setting "command_pack" (noto-animated), then to the order of the packs.
	 */
	public function search(string $query, ?string $userId): ?array {
		if (preg_match('/^([a-z0-9-]+)\/([A-Za-z0-9-]+)$/', trim($query), $exact) === 1) {
			return $this->find($exact[1], $exact[2], $userId) === null ? null : [$exact[1], $exact[2]];
		}
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
					$best = [$pack['link'], $sticker['id']];
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

	/**
	 * A sticker as an image file to send, in what Talk shows best: animated as GIF
	 * (Talk animates only GIFs), SVG and still WebP as PNG, at most 256 pixels.
	 * Null when the image cannot be read or converted (no Imagick).
	 *
	 * @return array{name: string, data: string, mime: string}|null
	 */
	public function imageFile(string $pack, string $id, ?string $userId): ?array {
		if ($pack === self::FAMILY) {
			$file = $userId === null ? null : $this->familyFile($userId, $id);
			if ($file === null) {
				return null;
			}
			$name = pathinfo($file->getName(), PATHINFO_FILENAME);
			$data = $file->getContent();
			$mime = $file->getMimetype();
		} else {
			$cached = $this->cachedImage($pack, $id);
			if ($cached !== null) {
				return $cached;
			}
			$sticker = $this->shippedSticker($pack, $id);
			$file = $sticker === null ? null : $this->shippedFile($pack, $sticker['f']);
			if ($file === null) {
				return null;
			}
			$name = $sticker['n'];
			$data = $file->getContent();
			$mime = ['png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][pathinfo($sticker['f'], PATHINFO_EXTENSION)] ?? '';
		}
		if ($mime === 'image/png' || $mime === 'image/jpeg' || $mime === 'image/gif') {
			return ['name' => $name, 'data' => $data, 'mime' => $mime];
		}
		$converted = $this->convert($data, $mime);
		if ($converted === null) {
			return null;
		}
		if ($pack !== self::FAMILY) {
			$this->cacheImage($pack, $id, $name, $converted);
		}
		return ['name' => $name, 'data' => $converted['data'], 'mime' => $converted['mime']];
	}

	/**
	 * WebP or SVG as GIF (animated) or PNG, at most 256 pixels, or null.
	 *
	 * @return array{data: string, mime: string}|null
	 */
	private function convert(string $data, string $mime): ?array {
		if (($mime !== 'image/webp' && $mime !== 'image/svg+xml') || !class_exists(\Imagick::class)) {
			return null;
		}
		try {
			$image = new \Imagick();
			$image->setBackgroundColor(new \ImagickPixel('transparent'));
			if ($mime === 'image/svg+xml') {
				$image->setResolution(384, 384);
			}
			$image->readImageBlob($data);
			if ($image->getNumberImages() > 1) {
				$frames = $image->coalesceImages();
				foreach ($frames as $frame) {
					$frame->thumbnailImage(256, 256, true);
					$frame->setImageFormat('gif');
					$frame->setImageDispose(2);
				}
				$frames->setImageIterations(0);
				return ['data' => $frames->getImagesBlob(), 'mime' => 'image/gif'];
			}
			$image->thumbnailImage(256, 256, true);
			$image->setImageFormat('png');
			return ['data' => $image->getImageBlob(), 'mime' => 'image/png'];
		} catch (\ImagickException) {
			return null;
		}
	}

	/**
	 * A shipped sticker converted before (converting an animated one takes seconds):
	 * app data converted/<pack>-<id>.gif or .png, the name in a .name file beside it.
	 */
	private function cachedImage(string $pack, string $id): ?array {
		try {
			$folder = $this->appDataFactory->get(Application::APP_ID)->getFolder('converted');
			foreach (['gif' => 'image/gif', 'png' => 'image/png'] as $extension => $mime) {
				if ($folder->fileExists("$pack-$id.$extension")) {
					return [
						'name' => $folder->getFile("$pack-$id.name")->getContent(),
						'data' => $folder->getFile("$pack-$id.$extension")->getContent(),
						'mime' => $mime,
					];
				}
			}
		} catch (NotFoundException) {
		}
		return null;
	}

	private function cacheImage(string $pack, string $id, string $name, array $converted): void {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			try {
				$folder = $appData->getFolder('converted');
			} catch (NotFoundException) {
				$folder = $appData->newFolder('converted');
			}
			$extension = $converted['mime'] === 'image/gif' ? 'gif' : 'png';
			$folder->newFile("$pack-$id.name", $name);
			$folder->newFile("$pack-$id.$extension", $converted['data']);
		} catch (\Throwable) {
			// Converted again next time.
		}
	}

	/** A shipped sticker's entry in the index ({i, f, n, …}), or null. */
	private function shippedSticker(string $pack, string $id): ?array {
		foreach ($this->shipped()['packs'] ?? [] as $shipped) {
			if ($shipped['id'] === $pack) {
				foreach ($shipped['stickers'] as $sticker) {
					if ($sticker['i'] === $id) {
						return $sticker;
					}
				}
			}
		}
		return null;
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

	/** The shipped packs' index.json (Packs\\Builder), or [] before they are built. */
	private function shipped(): array {
		if ($this->index === null) {
			$folder = $this->packsFolder();
			try {
				$data = $folder === null ? null : json_decode($folder->getFile('index.json')->getContent(), true);
			} catch (NotFoundException) {
				$data = null;
			}
			$this->index = is_array($data) ? $data : [];
		}
		return $this->index;
	}

	/** A shipped sticker's image file, or null. */
	public function shippedFile(string $pack, string $file): ?ISimpleFile {
		$folder = $this->packsFolder();
		try {
			return $folder?->getFolder($pack)->getFile($file);
		} catch (NotFoundException) {
			return null;
		}
	}

	/** The app data's folder of the packs in use (app setting packs_folder), or null. */
	private function packsFolder(): ?ISimpleFolder {
		$name = $this->appConfig->getValueString(Application::APP_ID, 'packs_folder', '');
		if ($name === '') {
			return null;
		}
		try {
			return $this->appDataFactory->get(Application::APP_ID)->getFolder($name);
		} catch (NotFoundException) {
			return null;
		}
	}

	private function shippedBase(): string {
		// The image route's URL without its pack and file, which the picker appends.
		$url = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.sticker.image', ['pack' => 'p', 'file' => 'f.png']);
		return substr($url, 0, -strlen('p/f.png'));
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
