<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Controller;

use OCA\TalkStickers\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * The stickers as a page of their own, for phones: Talk's apps have no Smart
 * Picker, so a sticker is shared from here to Talk (as an image) or its link
 * copied. Added to the home screen, it opens like an app (Nextcloud adds the
 * manifest, the theming app's for each app).
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, 'talk_stickers');
		Util::addStyle(Application::APP_ID, 'talk_stickers');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
