<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesReminders\Service;

use DateTime;
use DateTimeZone;
use OCA\FilesReminders\AppInfo\Application;
use OCA\FilesReminders\Db\Reminder;
use OCA\FilesReminders\Db\ReminderMapper;
use OCA\FilesReminders\Exception\NodeNotFoundException;
use OCA\FilesReminders\Exception\UserNotFoundException;
use OCA\FilesReminders\Model\RichReminder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

class ReminderService {
	public function __construct(
		protected IUserManager $userManager,
		protected IURLGenerator $urlGenerator,
		protected INotificationManager $notificationManager,
		protected ReminderMapper $reminderMapper,
		protected IRootFolder $root,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function get(int $id): RichReminder {
		$reminder = $this->reminderMapper->find($id);
		return new RichReminder($reminder, $this->root);
	}

	/**
	 * @throws NodeNotFoundException
	 * @throws DoesNotExistException
	 */
	public function getDueForUser(IUser $user, int $fileId): RichReminder {
		$this->checkNode($user, $fileId);
		$reminder = $this->reminderMapper->findDueForUser($user, $fileId);
		return new RichReminder($reminder, $this->root);
	}

	/**
	 * @return RichReminder[]
	 */
	public function getAll(?IUser $user = null) {
		$reminders = ($user !== null)
			? $this->reminderMapper->findAllForUser($user)
			: $this->reminderMapper->findAll();
		return array_map(
			fn (Reminder $reminder) => new RichReminder($reminder, $this->root),
			$reminders,
		);
	}

	/**
	 * @return bool true if created, false if updated
	 *
	 * @throws NodeNotFoundException
	 */
	public function createOrUpdate(IUser $user, int $fileId, DateTime $dueDate): bool {
		$now = new DateTime('now', new DateTimeZone('UTC'));
		$this->checkNode($user, $fileId);
		try {
			$reminder = $this->reminderMapper->findDueForUser($user, $fileId);
			$reminder->setDueDate($dueDate);
			$reminder->setUpdatedAt($now);
			$this->reminderMapper->update($reminder);
			return false;
		} catch (DoesNotExistException $e) {
			// Create new reminder if no reminder is found
			$reminder = new Reminder();
			$reminder->setUserId($user->getUID());
			$reminder->setFileId($fileId);
			$reminder->setDueDate($dueDate);
			$reminder->setUpdatedAt($now);
			$reminder->setCreatedAt($now);
			$this->reminderMapper->insert($reminder);
			return true;
		}
	}

	/**
	 * @throws NodeNotFoundException
	 * @throws DoesNotExistException
	 */
	public function remove(IUser $user, int $fileId): void {
		$this->checkNode($user, $fileId);
		$reminder = $this->reminderMapper->findDueForUser($user, $fileId);
		$this->reminderMapper->delete($reminder);
	}

	public function removeAllForNode(Node $node): void {
		$reminders = $this->reminderMapper->findAllForNode($node);
		foreach ($reminders as $reminder) {
			$this->reminderMapper->delete($reminder);
		}
	}

	public function removeAllForUser(IUser $user): void {
		$reminders = $this->reminderMapper->findAllForUser($user);
		foreach ($reminders as $reminder) {
			$this->reminderMapper->delete($reminder);
		}
	}

	/**
	 * @throws DoesNotExistException
	 * @throws UserNotFoundException
	 */
	public function send(Reminder $reminder): void {
		if ($reminder->getNotified()) {
			return;
		}

		$user = $this->userManager->get($reminder->getUserId());
		if ($user === null) {
			throw new UserNotFoundException();
		}

		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('files', 'folder.svg')))
			->setUser($user->getUID())
			->setObject('reminder', (string)$reminder->getId())
			->setSubject('reminder-due', [
				'fileId' => $reminder->getFileId(),
			])
			->setDateTime($reminder->getDueDate());

		try {
			$this->notificationManager->notify($notification);
			$this->reminderMapper->markNotified($reminder);
		} catch (Throwable $th) {
			$this->logger->error($th->getMessage(), $th->getTrace());
		}
	}

	public function cleanUp(?int $limit = null): void {
		$buffer = (new DateTime())
			->setTimezone(new DateTimeZone('UTC'))
			->modify('-1 day');
		$reminders = $this->reminderMapper->findNotified($buffer, $limit);
		foreach ($reminders as $reminder) {
			$this->reminderMapper->delete($reminder);
		}
	}

	/**
	 * @throws NodeNotFoundException
	 */
	private function checkNode(IUser $user, int $fileId): void {
		$userFolder = $this->root->getUserFolder($user->getUID());
		$node = $userFolder->getFirstNodeById($fileId);
		if ($node === null) {
			throw new NodeNotFoundException();
		}
	}
}
