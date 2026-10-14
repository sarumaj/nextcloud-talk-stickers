<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		// The catalogue the picker shows: shipped packs and the person's own.
		['name' => 'sticker#packs', 'url' => '/api/packs', 'verb' => 'GET'],
		// A sent sticker: the link in the message, and its image.
		['name' => 'sticker#show', 'url' => '/s/{pack}/{id}', 'verb' => 'GET',
			'requirements' => ['pack' => '[a-z0-9-]+', 'id' => '[A-Za-z0-9-]+']],
	],
];
