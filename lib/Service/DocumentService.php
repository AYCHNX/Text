<?php
/**
 * @copyright Copyright (c) 2019 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Text\Service;

use http\Exception\InvalidArgumentException;
use function json_encode;
use OC\Files\Node\File;
use OCA\Text\Db\Document;
use OCA\Text\Db\DocumentMapper;
use OCA\Text\Db\SessionMapper;
use OCA\Text\Db\Step;
use OCA\Text\Db\StepMapper;
use OCA\Text\DocumentSaveConflictException;
use OCA\Text\VersionMismatchException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\Files\IAppData;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\Lock\ILockingProvider;

class DocumentService {

	/**
	 * Delay to wait for between autosave versions
	 */
	const AUTOSAVE_MINIMUM_DELAY = 60;

	private $sessionMapper;
	private $userId;
	private $documentMapper;
	private $logger;

	public function __construct(SessionMapper $sessionMapper, DocumentMapper $documentMapper, StepMapper $stepMapper, IAppData $appData, $userId, IRootFolder $rootFolder, ICacheFactory $cacheFactory, ILogger $logger) {
		$this->sessionMapper = $sessionMapper;
		$this->documentMapper = $documentMapper;
		$this->stepMapper = $stepMapper;
		$this->userId = $userId;
		$this->appData = $appData;
		$this->rootFolder = $rootFolder;
		$this->cache = $cacheFactory->createDistributed('text');
		$this->logger = $logger;

		try {
			$this->appData->getFolder('documents');
		} catch (NotFoundException $e) {
			$this->appData->newFolder('documents');
		}
	}

	/**
	 * @param $path
	 * @return Entity
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 * @throws NotPermittedException
	 */
	public function createDocumentByPath($path) {
		/** @var File $file */
		$file = $this->rootFolder->getUserFolder($this->userId)->get($path);
		return $this->createDocument($file);
	}

	/**
	 * @param $fileId
	 * @return Entity
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 * @throws NotPermittedException
	 */
	public function getDocumentById($fileId) {
		return $this->createDocument($this->getFile($fileId));
	}

	public function getFile($fileId) {
		/** @var File $file */
		return $this->rootFolder->getUserFolder($this->userId)->getById($fileId)[0];
	}

	public function getFileForShare($token) {

		$userFolder = $this->rootFolder->getUserFolder($share->getShareOwner());
		$originalSharePath = $userFolder->getRelativePath($share->getNode()->getPath());


		// Single file share
		if ($share->getNode() instanceof \OCP\Files\File) {
			// Single file download
			return $share->getNode();
		}

		return null;
	}

	/**
	 * @param File $file
	 * @return Entity
	 * @throws NotFoundException
	 * @throws InvalidPathException
	 * @throws NotPermittedException
	 */
	protected function createDocument(File $file) {
		try {
			$document = $this->documentMapper->find($file->getFileInfo()->getId());

			// Do not hard reset if changed from outside since this will throw away possible steps
			// This way the user can still resolve conflicts in the editor view
			if ($document->getLastSavedVersion() !== $document->getCurrentVersion()) {
				$this->logger->debug('Unsaved steps but collission with file, continue collaborative editing');
				return $document;
			}

			// TODO: Only do this when no sessions active, otherise we need to resolve the conflict differently
			// TODO: Add parameter so that we can force this, else just opening the document will cause a rebuild
			$lastMTime = $document->getLastSavedVersionTime();
			if ($file->getMTime() > $lastMTime && $lastMTime > 0) {
				$this->resetDocument($document->getId());
				throw new NotFoundException();
			}

			return $document;
		} catch (DoesNotExistException $e) {
		} catch (InvalidPathException $e) {
		} catch (NotFoundException $e) {
		}

		try {
			$documentBaseFile = $this->appData->getFolder('documents')->getFile($file->getFileInfo()->getId());
		} catch (NotFoundException $e) {
			$documentBaseFile = $this->appData->getFolder('documents')->newFile($file->getFileInfo()->getId());
		}
		$documentBaseFile->putContent($file->fopen('r'));

		$document = new Document();
		$document->setId($file->getFileInfo()->getId());
		$document->setCurrentVersion(0);
		$document->setLastSavedVersion(0);
		$document->setLastSavedVersionTime($file->getFileInfo()->getMtime());
		$document->setLastSavedVersionEtag($file->getEtag());
		$document->setBaseVersionEtag($file->getEtag());
		$document = $this->documentMapper->insert($document);
		$this->cache->set('document-version-'.$document->getId(), 0);
		return $document;
	}

	/**
	 * @param $document
	 * @return ISimpleFile
	 * @throws NotFoundException
	 */
	public function getBaseFile($document) {
		return $this->appData->getFolder('documents')->getFile($document);
	}

	/**
	 * @param $documentId
	 * @param $sessionId
	 * @param $steps
	 * @param $version
	 * @return array
	 * @throws DoesNotExistException
	 * @throws VersionMismatchException
	 */
	public function addStep($documentId, $sessionId, $steps, $version) {
		// TODO check cache
		$document = $this->documentMapper->find($documentId);
		if ($version !== $document->getCurrentVersion()) {
			throw new VersionMismatchException('Version does not match');
		}
		$stepsJson = json_encode($steps);
		if ($stepsJson === null) {
			throw new InvalidArgumentException('Failed to encode steps');
		}
		$newVersion = $document->getCurrentVersion() + count($steps);
		$document->setCurrentVersion($newVersion);
		$this->documentMapper->update($document);
		$step = new Step($stepsJson);
		$step->setData($stepsJson);
		$step->setSessionId($sessionId);
		$step->setDocumentId($documentId);
		$step->setVersion($version+1);
		$this->stepMapper->insert($step);
		$this->cache->set('document-version-'.$document->getId(), $newVersion);
		// TODO restore old version for document if adding steps has failed
		// TODO write steps to cache for quicker reading
		return $steps;
	}

	public function getSteps($documentId, $lastVersion) {
		return $this->stepMapper->find($documentId, $lastVersion);
	}

	/**
	 * @param $documentId
	 * @param $version
	 * @param $autoaveDocument
	 * @param bool $force
	 * @param bool $manualSave
	 * @return Document
	 * @throws DocumentSaveConflictException
	 * @throws DoesNotExistException
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws \OCP\Files\GenericFileException
	 */
	public function autosave($documentId, $version, $autoaveDocument, $force = false, $manualSave = false) {
		/** @var Document $document */
		$document = $this->documentMapper->find($documentId);

		/** @var File $file */
		$file = $this->rootFolder->getUserFolder($this->userId)->getById($documentId)[0];

		$lastMTime = $document->getLastSavedVersionTime();
		if ($lastMTime > 0 && $file->getEtag() !== $document->getLastSavedVersionEtag() && $force === false) {
			throw new DocumentSaveConflictException('File changed in the meantime from outside');
		}

		if ($autoaveDocument === null) {
			return $document;
		}

		// TODO: check for etag rather than mtime
		// Do not save if version already saved
		if (!$force && $version <= (string)$document->getLastSavedVersion()) {
			return $document;
		}
		// Only save once every AUTOSAVE_MINIMUM_DELAY seconds
		if ($file->getMTime() === $lastMTime && $lastMTime > time() - self::AUTOSAVE_MINIMUM_DELAY && $manualSave === false) {
			return $document;
		}
		$file->putContent($autoaveDocument);
		$document->setLastSavedVersion($version);
		$document->setLastSavedVersionTime(time());
		$document->setLastSavedVersionEtag($file->getEtag());
		$this->documentMapper->update($document);
		return $document;
	}

	public function resetDocument($documentId) {
		$this->stepMapper->deleteAll($documentId);
		try {
			$document = $this->documentMapper->find($documentId);
			$this->documentMapper->delete($document);
		} catch (DoesNotExistException $e) {
		}

		try {
			$this->appData->getFolder('documents')->getFile($documentId)->delete();
		} catch (NotFoundException $e) {
		} catch (NotPermittedException $e) {
		}
	}

}
