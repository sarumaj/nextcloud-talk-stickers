<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Settings;

use OCA\TalkStickers\AppInfo\Application;
use OCA\TalkStickers\BackgroundJob\BuildPacks;
use OCA\TalkStickers\Packs\Builder;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * The app's settings in Administration settings → Additional settings → Talk stickers: the
 * team folder, the packs built and the pack /sticker prefers. Nextcloud's form saves each
 * change as it is made (no Save button). The same app settings as occ config:app:set.
 *
 * The form's id does not begin with the app's id and "_": Nextcloud's settings page takes
 * that off a form's id before it saves a value, and the value would go to no form.
 */
class AdminSettings implements IDeclarativeSettingsFormWithHandlers {
	public const FORM = 'settings';

	public function __construct(
		private IAppConfig $appConfig,
		private IJobList $jobList,
		private IL10N $l,
	) {
	}

	/** The packs of the manifest: id => title. @return array<string, string> */
	private static function packs(): array {
		return array_column(Builder::manifest()['packs'], 'title', 'id');
	}

	public function getSchema(): array {
		$packs = self::packs();
		return [
			'id' => self::FORM,
			'priority' => 10,
			'section_type' => 'admin',
			'section_id' => 'additional',
			'storage_type' => 'external',
			'title' => $this->l->t('Talk stickers'),
			'description' => $this->l->t('Each change is saved as you make it (there is no Save button).'),
			'fields' => [
				[
					'id' => 'folder',
					'title' => $this->l->t('Team folder of your own stickers'),
					'description' => $this->l->t('The shared folder with the stickers people add, one subfolder per pack.'),
					'type' => 'text',
					'default' => 'Stickers',
					'placeholder' => 'Stickers',
				],
				[
					'id' => 'packs',
					'title' => $this->l->t('Packs to offer'),
					'description' => $this->l->t('A change is built by a background job within minutes (or now: occ talk_stickers:packs). None ticked: all.'),
					'type' => 'multi-checkbox',
					'default' => '',
					'options' => array_map(fn (string $id, string $title) => ['name' => $title, 'value' => $id], array_keys($packs), $packs),
				],
				[
					'id' => 'command_pack',
					'title' => $this->l->t('Pack /sticker prefers'),
					'description' => $this->l->t('When several stickers fit a word equally well.'),
					'type' => 'select',
					'default' => 'noto-animated',
					'options' => array_keys($packs),
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			'folder' => $this->appConfig->getValueString(Application::APP_ID, 'folder', 'Stickers'),
			'command_pack' => $this->appConfig->getValueString(Application::APP_ID, 'command_pack', 'noto-animated'),
			// The page's multi-checkbox reads JSON: each pack ticked or not (none set: all).
			'packs' => json_encode(self::ticked(trim($this->appConfig->getValueString(Application::APP_ID, 'packs', '')))),
			default => '',
		};
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case 'folder':
				$folder = trim((string)$value, " \t\n/");
				$this->appConfig->setValueString(Application::APP_ID, 'folder', $folder === '' ? 'Stickers' : $folder);
				break;
			case 'command_pack':
				if (isset(self::packs()[(string)$value])) {
					$this->appConfig->setValueString(Application::APP_ID, 'command_pack', (string)$value);
				}
				break;
			case 'packs':
				$wanted = self::chosen(is_array($value) ? $value : (array)json_decode((string)$value, true));
				if ($wanted !== $this->appConfig->getValueString(Application::APP_ID, 'packs', '')) {
					$this->appConfig->setValueString(Application::APP_ID, 'packs', $wanted);
					// Built anew by the background job (its check: the packs' version).
					$this->appConfig->setValueString(Application::APP_ID, 'packs_version', '');
					if (!$this->jobList->has(BuildPacks::class, null)) {
						$this->jobList->add(BuildPacks::class);
					}
				}
				break;
		}
	}

	/**
	 * The packs ticked of a setting's ids (comma-separated; "": all).
	 * @return array<string, bool>
	 */
	public static function ticked(string $setting): array {
		$ids = $setting === '' ? array_keys(self::packs()) : array_map('trim', explode(',', $setting));
		return array_map(fn (string $id) => in_array($id, $ids, true), array_combine(array_keys(self::packs()), array_keys(self::packs())));
	}

	/**
	 * The setting of the packs ticked: their ids, comma-separated; "" when all or none are.
	 * @param array<array-key, mixed> $ticked
	 */
	public static function chosen(array $ticked): string {
		$ids = array_values(array_filter(array_keys(self::packs()), fn (string $id) => !empty($ticked[$id])));
		return count($ids) === count(self::packs()) ? '' : implode(',', $ids);
	}
}
