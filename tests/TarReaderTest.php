<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Tests;

use OCA\TalkStickers\Packs\TarReader;
use PHPUnit\Framework\TestCase;

class TarReaderTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/tar-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	/** A .tar.gz with ustar, GNU long-name and pax entries, as GitHub's archives have. */
	private function archive(): string {
		$long = 'repo-1/assets/' . str_repeat('very-long-folder-name/', 6) . 'metadata.json';
		$entries = [
			$this->header('pax_global_header', 52, 'g') . $this->pad("52 comment=0123456789012345678901234567890123456\n"),
			$this->header('repo-1/assets/a/3D/a_3d.png', 3) . $this->pad('PNG'),
			$this->header('repo-1/skip.txt', 4) . $this->pad('skip'),
			$this->header('././@LongLink', strlen($long) + 1, 'L') . $this->pad($long . "\0"),
			$this->header(substr($long, 0, 99), 2) . $this->pad('{}'),
			$this->header('PaxHeader', strlen($this->pax('repo-1/assets/b/pax.svg')), 'x') . $this->pad($this->pax('repo-1/assets/b/pax.svg')),
			$this->header('truncated-name', 5) . $this->pad('<svg>'),
			$this->header('repo-1/../evil.svg', 4) . $this->pad('evil'),
			$this->header('repo-1/link.svg', 0, '2'),
		];
		$path = $this->dir . '/a.tar.gz';
		file_put_contents($path, gzencode(implode('', $entries) . str_repeat("\0", 1024)));
		return $path;
	}

	private function pax(string $path): string {
		$record = ' path=' . $path . "\n";
		$length = strlen($record) + 2;
		return $length . $record;
	}

	private function header(string $name, int $size, string $type = '0'): string {
		$header = str_pad($name, 100, "\0") . str_pad('0000644', 8, "\0") . str_repeat("\0", 16)
			. str_pad(sprintf('%011o', $size), 12, "\0") . str_pad('0', 12, "\0") . '        ' . $type
			. str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
		$checksum = array_sum(array_map('ord', str_split($header)));
		return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
	}

	private function pad(string $data): string {
		return $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
	}

	public function testExtractsWantedFilesWithLongAndPaxNames(): void {
		$files = TarReader::extract($this->archive(), $this->dir . '/out', fn (string $name) => !str_ends_with($name, '.txt'));
		$long = 'repo-1/assets/' . str_repeat('very-long-folder-name/', 6) . 'metadata.json';
		$this->assertSame(['repo-1/assets/a/3D/a_3d.png', $long, 'repo-1/assets/b/pax.svg'], $files);
		$this->assertSame('PNG', file_get_contents($this->dir . '/out/repo-1/assets/a/3D/a_3d.png'));
		$this->assertSame('{}', file_get_contents($this->dir . '/out/' . $long));
		$this->assertSame('<svg>', file_get_contents($this->dir . '/out/repo-1/assets/b/pax.svg'));
		$this->assertFileDoesNotExist($this->dir . '/evil.svg');
	}
}
