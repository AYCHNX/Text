<?php
declare(strict_types=1);
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Text\DAV;

use OC\Files\Node\File;
use OC\Files\Node\Folder;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Files\FilesHome;
use OCA\Text\Service\WorkspaceService;
use OCP\Files\IRootFolder;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

class WorkspacePlugin extends ServerPlugin {

	public const WORKSPACE = '{http://nextcloud.org/ns}rich-workspace';

	/** @var Server */
	private $server;

	/** @var WorkspaceService */
	private $workspaceService;

	/**  @var IRootFolder */
	private $rootFolder;

	/** @var string|null */
	private $userId;

	public function __construct(WorkspaceService $workspaceService, IRootFolder $rootFolder, $userId) {
		$this->workspaceService = $workspaceService;
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @param Server $server
	 * @return void
	 */
	function initialize(Server $server) {
		$this->server = $server;

		$this->server->on('propFind', [$this, 'propFind']);
	}


	public function propFind(PropFind $propFind, INode $node) {
		if (!$node instanceof Directory && !$node instanceof FilesHome) {
			return;
		}

		$propFind->handle(self::WORKSPACE, function () use ($node) {
			/** @var Folder[] $nodes */
			$nodes = $this->rootFolder->getUserFolder($this->userId)->getById($node->getId());
			if (count($nodes) > 0) {
				/** @var File $file */
				$file = $this->workspaceService->getFile($nodes[0]);
				if ($file instanceof File) {
					return $file->getContent();
				}
			}
		});

	}

}
