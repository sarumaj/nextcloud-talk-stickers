<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkStickers\Tests;

require_once __DIR__ . '/stubs/files_sharing_events.php';

use OCA\Files_Sharing\Event\UserShareAccessUpdatedEvent;
use OCA\TalkStickers\Listener\QuietFolderShareListener;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** IManager as of Nextcloud 33, which nextcloud/ocp's stable32 lacks. */
interface ShareManager33 extends IManager {
	/** @return iterable<IUser> */
	public function getUsersForShare(IShare $share): iterable;
}

class QuietFolderShareListenerTest extends TestCase {
	protected function tearDown(): void {
		QuietFolderShareListener::$quietFor = null;
	}

	/**
	 * Talk's "shared a folder" is kept out, but the recipients' mounts are still
	 * updated (as files_sharing's own listener of ShareCreatedEvent would): without
	 * that, Talk names the recipient's folder from the sender's side.
	 */
	public function testQuietShareStillReachesTheRecipients(): void {
		$share = $this->roomShare('room1');
		$ben = $this->user('ben');
		$anna = $this->user('anna');
		$manager = $this->createMock(ShareManager33::class);
		$manager->method('getUsersForShare')->with($share)->willReturn([$ben, $anna]);
		$dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event) use (&$dispatched): void {
			$dispatched[] = $event;
		});

		QuietFolderShareListener::$quietFor = 'room1';
		$event = new ShareCreatedEvent($share);
		(new QuietFolderShareListener($dispatcher, $manager))->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertCount(1, $dispatched);
		$this->assertInstanceOf(UserShareAccessUpdatedEvent::class, $dispatched[0]);
		$this->assertSame([$anna], $dispatched[0]->getUsers());
	}

	public function testOtherSharesAreLeftAlone(): void {
		$manager = $this->createMock(ShareManager33::class);
		$manager->expects($this->never())->method('getUsersForShare');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$listener = new QuietFolderShareListener($dispatcher, $manager);

		$outside = new ShareCreatedEvent($this->roomShare('room1'));
		$listener->handle($outside);
		QuietFolderShareListener::$quietFor = 'room1';
		$otherRoom = new ShareCreatedEvent($this->roomShare('room2'));
		$listener->handle($otherRoom);

		$this->assertFalse($outside->isPropagationStopped());
		$this->assertFalse($otherRoom->isPropagationStopped());
	}

	private function roomShare(string $token): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
		$share->method('getSharedWith')->willReturn($token);
		$share->method('getShareOwner')->willReturn('ben');
		$share->method('getSharedBy')->willReturn('ben');
		return $share;
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
