<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Packs;

use OCA\TalkStickers\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ITempManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Builds the shipped sticker packs from their upstream sources (manifest.json
 * beside this file) into the app data: packs-<time>/<pack>/<sticker id>.<ext>
 * and packs-<time>/index.json, the catalogue the picker gets. Archives are
 * checked against their pinned SHA-256. The app setting "packs_folder" names the complete build in use;
 * it changes only once a new build is complete, and the old one is removed then.
 * One build at a time: a second one (the background job while occ builds) would
 * remove the first one's folder when it finishes, and the first the second's.
 * Skin-tone variants are left out: the picker shows each emoji once.
 */
class Builder {
	private const USER_AGENT = 'nextcloud-talk-stickers';
	/** OpenMoji's own groups, named for the picker. */
	private const OPENMOJI_GROUPS = ['extras-openmoji' => 'OpenMoji extras', 'extras-unicode' => 'More symbols'];
	/** Parallel downloads from the emoji CDN. */
	private const WORKERS = 8;
	/** The lock of a build (Nextcloud's locking provider, released at the end). */
	private const LOCK = 'talk_stickers/packs-build';

	/** @var callable(string): void */
	private $report;

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
	) {
		$this->report = fn (string $line) => null;
	}

	/** @return array<string, mixed> */
	public static function manifest(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** What the packs are built from: changes with the manifest (and so with a new source). */
	public static function manifestVersion(): string {
		return substr(hash('sha256', (string)file_get_contents(__DIR__ . '/manifest.json')), 0, 16);
	}

	/** Whether the packs in use were built from this version's manifest. */
	public function upToDate(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'packs_version', '') === self::manifestVersion()
			&& $this->appConfig->getValueString(Application::APP_ID, 'packs_folder', '') !== '';
	}

	/** The packs to build: the app setting "packs" (ids, comma-separated), or all. */
	public function wantedPacks(): array {
		$all = array_column(self::manifest()['packs'], 'id');
		$setting = trim($this->appConfig->getValueString(Application::APP_ID, 'packs', ''));
		return $setting === '' ? $all : array_values(array_intersect($all, array_map('trim', explode(',', $setting))));
	}

	/**
	 * Builds the packs and puts them in use.
	 *
	 * @param list<string>|null $only these packs (default: wantedPacks())
	 * @param int|null $limit at most this many stickers per pack (for tests)
	 * @param callable(string): void|null $report progress lines
	 */
	public function build(?array $only = null, ?int $limit = null, ?callable $report = null): void {
		if ($report !== null) {
			$this->report = $report;
		}
		try {
			$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'the sticker packs');
		} catch (LockedException) {
			throw new BuildRunningException('The sticker packs are being built already; try again when that build is done.');
		}
		try {
			$this->buildLocked($only, $limit);
		} finally {
			$this->locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function buildLocked(?array $only, ?int $limit): void {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		$name = 'packs-' . gmdate('YmdHis');
		$out = $appData->newFolder($name);
		$work = $this->tempManager->getTemporaryFolder('talk_stickers');
		try {
			$manifest = self::manifest();
			$emoji = Emoji::parseEmojiTest($this->pinned($manifest['unicode']));
			$packs = [];
			foreach ($manifest['packs'] as $spec) {
				if (!in_array($spec['id'], $only ?? $this->wantedPacks(), true)) {
					continue;
				}
				$pack = new Pack($spec, $out->newFolder($spec['id']));
				match ($spec['kind']) {
					'fluent' => $this->buildFluent($pack, $spec, $emoji, $work, $limit),
					'noto-animated' => $this->buildNotoAnimated($pack, $spec, $emoji, $limit),
					'noto' => $this->buildNoto($pack, $spec, $emoji, $limit),
					'twemoji' => $this->buildTwemoji($pack, $spec, $emoji, $work, $limit),
					'openmoji' => $this->buildOpenmoji($pack, $spec, $emoji, $work, $limit),
					default => throw new \RuntimeException('Unknown kind of pack: ' . $spec['kind']),
				};
				if ($pack->count() === 0) {
					throw new \RuntimeException($spec['id'] . ': no stickers found; has the source changed?');
				}
				($this->report)($spec['id'] . ': ' . $pack->count() . ' stickers');
				$packs[] = $pack->index($emoji);
			}
			$out->newFile('index.json', json_encode(['version' => self::manifestVersion(), 'packs' => $packs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
			$out->delete();
			throw $e;
		} finally {
			$this->removeTree($work);
		}
		$old = $this->appConfig->getValueString(Application::APP_ID, 'packs_folder', '');
		$this->appConfig->setValueString(Application::APP_ID, 'packs_folder', $name);
		$this->appConfig->setValueString(Application::APP_ID, 'packs_version', self::manifestVersion());
		foreach ($appData->getDirectoryListing() as $folder) {
			// Stickers converted for Talk (StickerService::imageFile) were made from the old
			// images; downloads/ is what an earlier version kept.
			if ((str_starts_with($folder->getName(), 'packs-') && $folder->getName() !== $name)
				|| in_array($folder->getName(), ['converted', 'downloads'], true)) {
				$folder->delete();
			}
		}
		($this->report)($old === '' ? 'The packs are in use.' : 'The new packs are in use; the old ones are removed.');
	}

	/** Microsoft's Fluent Emoji: the 3D images (Default for those with skin tones). */
	private function buildFluent(Pack $pack, array $spec, array $emoji, string $work, ?int $limit): void {
		$dir = $work . '/fluent';
		$files = TarReader::extract($this->pinnedFile($spec['archive']), $dir,
			fn (string $name) => str_ends_with($name, '/metadata.json') || (str_contains($name, '/3D/') && str_ends_with($name, '_3d.png')));
		// The 3D images by emoji folder; skin-tone emoji keep their neutral ones under Default/.
		$images = [];
		foreach ($files as $name) {
			foreach (['/Default/3D/', '/3D/'] as $marker) {
				if (str_contains($name, $marker)) {
					$images[explode($marker, $name, 2)[0]][] = $name;
					break;
				}
			}
		}
		sort($files);
		foreach ($files as $name) {
			if (!str_ends_with($name, '/metadata.json') || substr_count($name, '/assets/') !== 1) {
				continue;
			}
			if ($limit !== null && $pack->count() >= $limit) {
				break;
			}
			$folder = substr($name, 0, strrpos($name, '/'));
			$meta = json_decode((string)file_get_contents($dir . '/' . $name), true);
			$found = $images[$folder] ?? [];
			if ($found === [] || !is_array($meta) || !isset($meta['unicode'])) {
				continue;
			}
			sort($found);
			$id = Emoji::stickerId(Emoji::parseCodepoints($meta['unicode']));
			$pack->add($id, 'png', (string)file_get_contents($dir . '/' . $found[0]), $meta['cldr'] ?? $id,
				$this->category($id, $emoji, $meta['group'] ?? ''), $meta['keywords'] ?? []);
		}
	}

	/** Google's animated Noto Emoji, from its CDN (the index lists the animated ones). */
	private function buildNotoAnimated(Pack $pack, array $spec, array $emoji, ?int $limit): void {
		$icons = json_decode($this->fetch($spec['index']), true, 512, JSON_THROW_ON_ERROR)['icons'];
		if ($limit !== null) {
			$icons = array_slice($icons, 0, $limit);
		}
		$urls = [];
		foreach ($icons as $icon) {
			$urls[$icon['codepoint']] = str_replace('{codepoint}', $icon['codepoint'], $spec['url']);
		}
		$files = $this->fetchAll($urls);
		foreach ($icons as $icon) {
			$data = $files[$icon['codepoint']] ?? null;
			if ($data === null) {
				continue;
			}
			$id = Emoji::stickerId(Emoji::parseCodepoints($icon['codepoint']));
			$keywords = array_map(fn (string $tag) => str_replace('-', ' ', trim($tag, ':')), $icon['tags'] ?? []);
			$pack->add($id, 'webp', $data, $emoji[$id]['name'] ?? ($keywords[0] ?? $id),
				$this->category($id, $emoji, $icon['categories'][0] ?? ''), $keywords);
		}
	}

	/** Google's Noto Emoji, the still images from its CDN. */
	private function buildNoto(Pack $pack, array $spec, array $emoji, ?int $limit): void {
		$entries = $this->withoutSkinTones($emoji, $limit);
		$urls = [];
		foreach ($entries as $id => $entry) {
			$codepoint = implode('_', array_map('dechex', array_values(array_filter($entry['codepoints'], fn (int $cp) => $cp !== 0xFE0F))));
			$urls[$id] = str_replace('{codepoint}', $codepoint, $spec['url']);
		}
		$files = $this->fetchAll($urls);
		foreach ($entries as $id => $entry) {
			if (($files[$id] ?? null) !== null) {
				$pack->add((string)$id, 'png', $files[$id], $entry['name'], $entry['group']);
			}
		}
	}

	/** Twemoji (jdecked's continuation of Twitter's): its SVG images. */
	private function buildTwemoji(Pack $pack, array $spec, array $emoji, string $work, ?int $limit): void {
		$dir = $work . '/twemoji';
		$svgs = [];
		foreach (TarReader::extract($this->pinnedFile($spec['archive']), $dir,
			fn (string $name) => str_contains($name, '/assets/svg/') && str_ends_with($name, '.svg')) as $name) {
			$svgs[basename($name)] = $dir . '/' . $name;
		}
		foreach ($this->withoutSkinTones($emoji, $limit) as $id => $entry) {
			// Twemoji keeps FE0F in some file names and drops it in others.
			$candidates = [$id . '.svg', implode('-', array_map('dechex', $entry['codepoints'])) . '.svg'];
			foreach ($candidates as $candidate) {
				if (isset($svgs[$candidate])) {
					$pack->add((string)$id, 'svg', (string)file_get_contents($svgs[$candidate]), $entry['name'], $entry['group']);
					break;
				}
			}
		}
	}

	/** OpenMoji: the coloured 618×618 images, with OpenMoji's own extras. */
	private function buildOpenmoji(Pack $pack, array $spec, array $emoji, string $work, ?int $limit): void {
		$meta = json_decode($this->pinned($spec['metadata']), true, 512, JSON_THROW_ON_ERROR);
		$zip = new \ZipArchive();
		if ($zip->open($this->pinnedFile($spec['archive'])) !== true) {
			throw new \RuntimeException('OpenMoji: the archive cannot be opened');
		}
		try {
			foreach ($meta as $entry) {
				if ($limit !== null && $pack->count() >= $limit) {
					break;
				}
				// Components (hair styles, skin tones) are parts of emoji, not stickers.
				$file = $entry['hexcode'] . '.png';
				if (!empty($entry['skintone']) || ($entry['group'] ?? '') === 'component' || $zip->locateName($file) === false) {
					continue;
				}
				$id = Emoji::stickerId(Emoji::parseCodepoints($entry['hexcode']));
				$group = self::OPENMOJI_GROUPS[$entry['group'] ?? ''] ?? ucwords(str_replace('-', ' ', $entry['group'] ?? ''));
				$keywords = explode(',', ($entry['tags'] ?? '') . ',' . ($entry['openmoji_tags'] ?? ''));
				$pack->add($id, 'png', (string)$zip->getFromName($file), ($entry['annotation'] ?? '') ?: $id,
					$this->category($id, $emoji, $group), $keywords);
			}
		} finally {
			$zip->close();
		}
	}

	private function category(string $id, array $emoji, string $fallback): string {
		return $emoji[$id]['group'] ?? ($fallback !== '' ? $fallback : 'Other');
	}

	private function withoutSkinTones(array $emoji, ?int $limit): array {
		$entries = array_filter($emoji, fn (array $entry) => !Emoji::hasSkinTone($entry['codepoints']));
		return $limit === null ? $entries : array_slice($entries, 0, $limit, true);
	}

	/** A pinned download ({url, sha256}): its content. */
	private function pinned(array $source): string {
		return (string)file_get_contents($this->pinnedFile($source));
	}

	/**
	 * A pinned download ({url, sha256}) as a temporary file (removed with the
	 * build's other temporary files); a wrong checksum stops the build.
	 */
	private function pinnedFile(array $source): string {
		$local = $this->tempManager->getTemporaryFile();
		($this->report)('Downloading ' . $source['url']);
		$this->clientService->newClient()->get($source['url'], ['sink' => $local, 'timeout' => 900, 'headers' => ['User-Agent' => self::USER_AGENT]]);
		$digest = hash_file('sha256', $local);
		if ($digest !== $source['sha256']) {
			throw new \RuntimeException($source['url'] . ': SHA-256 ' . $digest . ', expected ' . $source['sha256']);
		}
		return $local;
	}

	private function fetch(string $url): string {
		for ($attempt = 1; ; $attempt++) {
			try {
				return (string)$this->clientService->newClient()->get($url, ['timeout' => 60, 'headers' => ['User-Agent' => self::USER_AGENT]])->getBody();
			} catch (\Throwable $e) {
				if ($attempt >= 3) {
					throw $e;
				}
				sleep(2 ** $attempt);
			}
		}
	}

	/**
	 * Small files from the CDN, a few at a time: the content by key, null where
	 * the CDN has none (not every emoji is in every pack) or it kept failing.
	 *
	 * @param array<string, string> $urls
	 * @return array<string, string|null>
	 */
	private function fetchAll(array $urls): array {
		$client = $this->clientService->newClient();
		$files = [];
		foreach (array_chunk($urls, self::WORKERS, true) as $chunk) {
			$promises = [];
			foreach ($chunk as $key => $url) {
				$promises[$key] = $client->getAsync($url, ['timeout' => 60, 'headers' => ['User-Agent' => self::USER_AGENT]]);
			}
			foreach ($promises as $key => $promise) {
				try {
					$files[$key] = (string)$promise->wait()->getBody();
				} catch (\Throwable $e) {
					$files[$key] = $this->retry($chunk[$key], $e);
				}
			}
		}
		return $files;
	}

	/** One more try of a failed CDN download, unless it does not exist there (404). */
	private function retry(string $url, \Throwable $first): ?string {
		if (str_contains($first->getMessage(), '404')) {
			return null;
		}
		try {
			return $this->fetch($url);
		} catch (\Throwable $e) {
			$this->logger->info('Left out, the CDN failed: ' . $url, ['exception' => $e]);
			return null;
		}
	}

	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
