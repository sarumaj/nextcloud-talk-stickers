<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Controller;

use OCA\TalkStickers\Service\StickerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class StickerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private StickerService $stickers,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** The picker's catalogue. */
	#[NoAdminRequired]
	public function packs(): JSONResponse {
		return new JSONResponse($this->stickers->catalogue($this->userId));
	}

	/**
	 * A shipped sticker's image, to everyone: the packs are open-licensed, and the
	 * phone apps load a sent sticker's preview without a login. Never changes
	 * under its URL (a new build has new ids only where Unicode has new emoji).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function image(string $pack, string $file): Response {
		$image = $this->stickers->shippedFile($pack, $file);
		if ($image === null) {
			return new NotFoundResponse();
		}
		$extension = pathinfo($file, PATHINFO_EXTENSION);
		$response = new FileDisplayResponse($image, Http::STATUS_OK, [
			'Content-Type' => ['png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][$extension],
			'Content-Disposition' => 'inline',
			'X-Content-Type-Options' => 'nosniff',
			// SVGs are checked when the packs are built; this keeps one from doing anything anyway.
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
		]);
		$response->cacheFor(30 * 24 * 3600, true, true);
		return $response;
	}

	/**
	 * A sent sticker: a shipped one redirects to its image, one of the
	 * shared folder is streamed to those who can read that folder.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $pack, string $id): Response {
		if ($pack === StickerService::FAMILY) {
			$file = $this->userId === null ? null : $this->stickers->familyFile($this->userId, $id);
			if ($file === null) {
				return new NotFoundResponse();
			}
			$response = new FileDisplayResponse($file, Http::STATUS_OK, [
				'Content-Type' => $file->getMimetype(),
				'Content-Disposition' => 'inline',
				'X-Content-Type-Options' => 'nosniff',
			]);
			$response->cacheFor(3600, false, true);
			return $response;
		}
		$sticker = $this->stickers->find($pack, $id, $this->userId);
		return $sticker === null ? new NotFoundResponse() : new RedirectResponse($sticker['image']);
	}
}
