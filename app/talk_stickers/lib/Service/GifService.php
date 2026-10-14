<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * GIFs from GIPHY for /gif and GIPHY links, with the API key and rating of the
 * GIPHY integration (integration_giphy, which stores its key encrypted itself).
 */
class GifService {
	private const API = 'https://api.giphy.com/v1/gifs/';
	/** Talk animates GIFs up to this size; larger ones are taken smaller. */
	private const MAX_SIZE = 3 * 1024 * 1024;

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private IClientService $clientService,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	/** Whether GIPHY can be asked: the GIPHY integration has a key. */
	public function available(): bool {
		return $this->apiKey() !== '';
	}

	/**
	 * The first GIF GIPHY finds for the words, in the user's language and at the
	 * integration's rating, or null.
	 *
	 * @return array{name: string, data: string, mime: string, page: string}|null
	 */
	public function search(string $query, string $userId): ?array {
		$language = substr($this->l10nFactory->getUserLanguage($this->userOf($userId)), 0, 2) ?: 'en';
		$found = $this->call('search', [
			'q' => $query,
			'limit' => 1,
			'rating' => $this->config->getAppValue('integration_giphy', 'rating', 'g') ?: 'g',
			'lang' => $language,
		]);
		$gif = $found['data'][0] ?? null;
		return is_array($gif) ? $this->download($gif) : null;
	}

	/**
	 * A GIF by its id, or null.
	 *
	 * @return array{name: string, data: string, mime: string, page: string}|null
	 */
	public function byId(string $id): ?array {
		$found = $this->call(rawurlencode($id), []);
		$gif = $found['data'] ?? null;
		return is_array($gif) && isset($gif['images']) ? $this->download($gif) : null;
	}

	/** The id of a GIF in a GIPHY link (a page or a media link), or null. */
	public static function idOfLink(string $text): ?string {
		$text = trim($text);
		if (preg_match('#^https?://(?:www\.)?giphy\.com/gifs/(?:[^/?\#\s]*-)?([A-Za-z0-9]+)/?(?:[?\#]\S*)?$#', $text, $match) === 1) {
			return $match[1];
		}
		if (preg_match('#^https?://(?:media\d*|i)\.giphy\.com/media/(?:v1\.[^/\s]+/)?([A-Za-z0-9]+)/[^/\s]+$#', $text, $match) === 1) {
			return $match[1];
		}
		return null;
	}

	private function call(string $path, array $parameters): ?array {
		$key = $this->apiKey();
		if ($key === '') {
			return null;
		}
		try {
			$response = $this->clientService->newClient()->get(self::API . $path, [
				'query' => ['api_key' => $key] + $parameters,
				'timeout' => 10,
			]);
			$data = json_decode((string)$response->getBody(), true);
			return is_array($data) ? $data : null;
		} catch (\Throwable $e) {
			$this->logger->warning('GIPHY did not answer: ' . $e->getMessage());
			return null;
		}
	}

	/** @return array{name: string, data: string, mime: string, page: string}|null */
	private function download(array $gif): ?array {
		$images = $gif['images'] ?? [];
		foreach (['downsized', 'fixed_height', 'fixed_height_small'] as $rendition) {
			$image = $images[$rendition] ?? null;
			if (!is_array($image) || empty($image['url']) || (int)($image['size'] ?? 0) > self::MAX_SIZE) {
				continue;
			}
			try {
				$data = (string)$this->clientService->newClient()->get($image['url'], ['timeout' => 15])->getBody();
			} catch (\Throwable $e) {
				$this->logger->warning('A GIF could not be downloaded from GIPHY: ' . $e->getMessage());
				return null;
			}
			if ($data === '' || strlen($data) > self::MAX_SIZE || !str_starts_with($data, 'GIF8')) {
				continue;
			}
			$title = trim(preg_replace('/\s+GIF(\s+by\s+.*)?$/iu', '', (string)($gif['title'] ?? '')) ?? '');
			return [
				'name' => $title !== '' ? $title : (string)($gif['id'] ?? 'GIF'),
				'data' => $data,
				'mime' => 'image/gif',
				'page' => (string)($gif['url'] ?? ''),
			];
		}
		return null;
	}

	/** The GIPHY integration's key, decrypted as that app does. */
	private function apiKey(): string {
		$stored = $this->config->getAppValue('integration_giphy', 'api_key', '');
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable) {
			return '';
		}
	}

	private function userOf(string $userId): ?\OCP\IUser {
		return \OCP\Server::get(\OCP\IUserManager::class)->get($userId);
	}
}
