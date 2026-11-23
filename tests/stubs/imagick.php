<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// For Psalm: what StickerService uses of the imagick extension (optional: it converts WebP and SVG
// stickers where the extension is installed), which Psalm does not know without it.

/** @implements Iterator<int, Imagick> */
class Imagick implements Iterator, Countable {
	public function setBackgroundColor(ImagickPixel|string $background): bool {
	}

	public function setResolution(float $xResolution, float $yResolution): bool {
	}

	public function readImageBlob(string $image, ?string $filename = null): bool {
	}

	public function getNumberImages(): int {
	}

	public function coalesceImages(): Imagick {
	}

	public function thumbnailImage(int $columns, int $rows, bool $bestfit = false, bool $fill = false, bool $legacy = false): bool {
	}

	public function setImageFormat(string $format): bool {
	}

	public function setImageDispose(int $dispose): bool {
	}

	public function setImageIterations(int $iterations): bool {
	}

	public function getImageBlob(): string {
	}

	public function getImagesBlob(): string {
	}

	public function current(): Imagick {
	}

	public function key(): int {
	}

	public function next(): void {
	}

	public function rewind(): void {
	}

	public function valid(): bool {
	}

	public function count(string $mode = ''): int {
	}
}

class ImagickPixel {
	public function __construct(?string $color = null) {
	}
}

class ImagickException extends Exception {
}
