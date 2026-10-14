<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Listener;

use OCA\TalkStickers\AppInfo\Application;
use OCA\TalkStickers\Service\StickerService;
use OCP\Comments\IComment;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * /sticker in Talk, from any client (the mobile apps have no Smart Picker):
 * before a message "/sticker <words, emoji or pack/id>" is saved, it becomes the
 * best-fitting sticker, sent by its author. As an image file when it can be: the
 * sticker is put into the author's folder of the conversation (Talk/<conversation>/
 * <author>/, which Talk shares with the conversation once a file was sent there)
 * and the message becomes Talk's own message of a file there, so every client
 * shows the image alone. Otherwise (no such folder yet, an older Talk) as the
 * sticker's link. A bare "/sticker" becomes the link of the stickers' page; words
 * that fit no sticker stay as typed. Listens to Talk's BeforeChatMessageSentEvent
 * (by name: Talk is not needed to install the app).
 *
 * @template-implements IEventListener<Event>
 */
class StickerCommandListener implements IEventListener {
	/** The command's names: English, German, Polish. */
	private const COMMAND = '/^\/(?:stickers?|naklejk[ai])(?:\s+(.*))?$/isu';
	/** Talk's verb of a shared file or object (ChatManager::VERB_OBJECT_SHARED). */
	private const VERB_OBJECT_SHARED = 'object_shared';

	public function __construct(
		private StickerService $stickers,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getComment') || !method_exists($event, 'getRoom')) {
			return;
		}
		$comment = $event->getComment();
		if ($comment->getActorType() !== 'users'
			|| preg_match(self::COMMAND, trim($comment->getMessage()), $match) !== 1) {
			return;
		}
		$userId = $comment->getActorId();
		$query = trim($match[1] ?? '');
		if ($query === '') {
			$page = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');
			$comment->setMessage($this->l10n->t('Stickers: %s', [$page]));
			return;
		}
		$found = $this->stickers->search($query, $userId);
		if ($found === null) {
			return;
		}
		[$pack, $id] = $found;
		if (!$this->sendAsFile($comment, $event->getRoom(), $userId, $pack, $id)) {
			$comment->setMessage($this->stickers->link($pack, $id));
		}
	}

	/** Turns the message into Talk's message of the sticker as a file; whether it could. */
	private function sendAsFile(IComment $comment, object $room, string $userId, string $pack, string $id): bool {
		try {
			$folder = $this->sharedConversationFolder($room, $userId);
			if ($folder === null) {
				return false;
			}
			$image = $this->stickers->imageFile($pack, $id, $userId);
			if ($image === null) {
				return false;
			}
			$file = $this->stickerFile($folder, $image);
			$comment->setVerb(self::VERB_OBJECT_SHARED);
			$comment->setMessage(json_encode([
				'message' => 'file_shared',
				'parameters' => ['fileId' => (string)$file->getId(), 'metaData' => ['mimeType' => $image['mime']]],
			], JSON_THROW_ON_ERROR));
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sending a sticker as a file failed, it goes as a link', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * The author's folder of the conversation, when Talk already shares it with the
	 * conversation (it does once a file was sent there). Creating that share here
	 * would make Talk post a message of its own about the folder.
	 */
	private function sharedConversationFolder(object $room, string $userId): ?Folder {
		$config = Server::get('OCA\\Talk\\Config');
		if (!$config->isConversationSubfoldersEnabled()) {
			return null;
		}
		$path = ltrim($config->getAttachmentFolder($userId), '/') . '/'
			. $config->getConversationFolderName($room, $userId) . '/'
			. $config->getConversationSubfolderName($userId, false);
		try {
			$folder = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			return null;
		}
		if (!$folder instanceof Folder) {
			return null;
		}
		$token = $room->getToken();
		foreach ($this->shareManager->getSharesBy($userId, IShare::TYPE_ROOM, $folder, false, -1) as $share) {
			if ($share->getSharedWith() === $token) {
				return $folder;
			}
		}
		return null;
	}

	/** The sticker's file in the folder: the one sent before, or a new one. */
	private function stickerFile(Folder $folder, array $image): File {
		$extension = ['image/gif' => 'gif', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$image['mime']] ?? 'png';
		$base = trim(preg_replace('/[\\\\\\/:*?"<>|\\x00-\\x1f]+/u', ' ', $image['name']) ?? '') ?: 'sticker';
		$name = $this->l10n->t('Sticker') . ' ' . $base . '.' . $extension;
		if ($folder->nodeExists($name)) {
			$existing = $folder->get($name);
			if ($existing instanceof File && $existing->getContent() === $image['data']) {
				return $existing;
			}
			$name = $this->l10n->t('Sticker') . ' ' . $base . ' ' . substr(sha1($image['data']), 0, 8) . '.' . $extension;
			if ($folder->nodeExists($name)) {
				$existing = $folder->get($name);
				if ($existing instanceof File) {
					return $existing;
				}
			}
		}
		return $folder->newFile($name, $image['data']);
	}
}
