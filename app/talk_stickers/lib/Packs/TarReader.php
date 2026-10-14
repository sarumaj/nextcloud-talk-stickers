<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Packs;

/**
 * Reads a .tar.gz in one pass, as a stream (the archives are hundreds of
 * megabytes), and writes the wanted regular files to a directory. Knows ustar
 * names (with prefix), GNU long names and pax path records, which GitHub's
 * archives use; links and other entries are skipped.
 */
class TarReader {
	/**
	 * @param callable(string): bool $wanted whether a file (by its path in the archive) is extracted
	 * @return list<string> the extracted paths, relative to $target
	 */
	public static function extract(string $archive, string $target, callable $wanted): array {
		$in = gzopen($archive, 'rb');
		if ($in === false) {
			throw new \RuntimeException('Cannot open ' . $archive);
		}
		$extracted = [];
		$longName = null;
		try {
			while (true) {
				$header = self::read($in, 512);
				if (strlen($header) < 512 || trim($header, "\0") === '') {
					break;
				}
				$size = (int)octdec(trim(substr($header, 124, 12), " \0"));
				$type = $header[156];
				$name = rtrim(substr($header, 0, 100), "\0");
				$prefix = rtrim(substr($header, 345, 155), "\0");
				if (substr($header, 257, 5) === 'ustar' && $prefix !== '') {
					$name = $prefix . '/' . $name;
				}
				$padded = (int)(ceil($size / 512) * 512);
				if ($type === 'L' || $type === 'x') {
					$data = substr(self::read($in, $padded), 0, $size);
					$longName = $type === 'L' ? rtrim($data, "\0") : (self::paxPath($data) ?? $longName);
					continue;
				}
				if ($longName !== null) {
					$name = $longName;
					$longName = null;
				}
				$safe = !str_contains('/' . $name . '/', '/../') && !str_starts_with($name, '/');
				if (($type === '0' || $type === "\0") && $safe && $wanted($name)) {
					$path = $target . '/' . $name;
					if (!is_dir(dirname($path))) {
						mkdir(dirname($path), 0700, true);
					}
					file_put_contents($path, substr(self::read($in, $padded), 0, $size));
					$extracted[] = $name;
				} else {
					self::skip($in, $padded);
				}
			}
		} finally {
			gzclose($in);
		}
		return $extracted;
	}

	/** The path of a pax extended header ("<length> path=<path>\n" records), or null. */
	private static function paxPath(string $data): ?string {
		foreach (explode("\n", $data) as $record) {
			if (preg_match('/^\d+ path=(.*)$/s', $record, $match) === 1) {
				return $match[1];
			}
		}
		return null;
	}

	/** @param resource $in */
	private static function read($in, int $length): string {
		$data = '';
		while (strlen($data) < $length) {
			$chunk = gzread($in, min($length - strlen($data), 1 << 20));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/** @param resource $in */
	private static function skip($in, int $length): void {
		while ($length > 0) {
			$chunk = gzread($in, min($length, 1 << 20));
			if ($chunk === false || $chunk === '') {
				return;
			}
			$length -= strlen($chunk);
		}
	}
}
