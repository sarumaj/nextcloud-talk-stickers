<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Tests;

use OCA\TalkStickers\BackgroundJob\BuildPacks;
use OCA\TalkStickers\Settings\AdminSettings;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The settings form in Additional settings: the app settings it reads and writes, the packs as
 * the page's boxes, a change of packs built anew by the background job, and a form id Nextcloud's
 * page saves to.
 */
class AdminSettingsTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];
	/** @var list<string> */
	private array $queued = [];

	private function form(): AdminSettings {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->config[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$jobs = $this->createStub(IJobList::class);
		$jobs->method('has')->willReturnCallback(fn (string $job) => in_array($job, $this->queued, true));
		$jobs->method('add')->willReturnCallback(function (string $job): void {
			$this->queued[] = $job;
		});
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return new AdminSettings($config, $jobs, $l);
	}

	public function testTheFormIsInAdditionalSettingsUnderAnIdThePageSavesTo(): void {
		$schema = $this->form()->getSchema();
		$this->assertSame(['admin', 'additional', 'external'], [$schema['section_type'], $schema['section_id'], $schema['storage_type']]);
		// Nextcloud's page takes "<app>_" off a form's id before it saves a value.
		$this->assertStringStartsNotWith('talk_stickers_', $schema['id']);
		$this->assertSame(['folder', 'packs', 'command_pack'], array_column($schema['fields'], 'id'));
	}

	public function testThePacksAreBoxesAndAChangeIsBuiltAnew(): void {
		$form = $this->form();
		$user = $this->createStub(IUser::class);
		$all = json_decode((string)$form->getValue('packs', $user), true);
		$this->assertSame(['fluent', 'noto-animated', 'noto', 'openmoji', 'twemoji'], array_keys($all));
		$this->assertNotContains(false, $all, 'none set: all');
		$this->config['packs_version'] = 'built';
		$form->setValue('packs', ['fluent' => true, 'noto' => true, 'twemoji' => false], $user);
		$this->assertSame('fluent,noto', $this->config['packs']);
		$this->assertSame('', $this->config['packs_version'], 'the packs are out of date');
		$this->assertSame([BuildPacks::class], $this->queued, 'and built by the background job');
		$form->setValue('packs', ['fluent' => true, 'noto' => true], $user);
		$this->assertSame([BuildPacks::class], $this->queued, 'no change: nothing queued again');
		$form->setValue('packs', array_fill_keys(array_keys($all), true), $user);
		$this->assertSame('', $this->config['packs'], 'all ticked: all');
	}

	public function testTheFolderAndThePreferredPack(): void {
		$form = $this->form();
		$user = $this->createStub(IUser::class);
		$this->assertSame('Stickers', $form->getValue('folder', $user));
		$form->setValue('folder', ' /Our stickers/ ', $user);
		$this->assertSame('Our stickers', $this->config['folder']);
		$form->setValue('folder', '', $user);
		$this->assertSame('Stickers', $this->config['folder'], 'emptied: the default');
		$form->setValue('command_pack', 'openmoji', $user);
		$form->setValue('command_pack', 'nonsense', $user);
		$this->assertSame('openmoji', $form->getValue('command_pack', $user), 'only a pack there is');
	}
}
