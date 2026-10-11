<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAV\Files\NodeAttributes;

use OC\Share20\Exception\BackendError;
use OCA\DAV\Connector\Sabre\SharesPlugin;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NodeAttributes\INodeAttributeProvider;
use OCP\Files\NodeAttributes\NodeAttributeContext;
use OCP\Files\NodeAttributes\NodeAttributeList;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Share types and sharees of a node, for logged in users
 *
 * A node on its own gets the shares the user created on it and the ones the user received for it.
 * Children of a folder loaded along with it only get the shares in that folder created by the user
 * or reshared from the user, the same as a folder listing has always shown.
 */
class SharesProvider implements INodeAttributeProvider {
	private const SHARE_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_LINK,
		IShare::TYPE_REMOTE,
		IShare::TYPE_EMAIL,
		IShare::TYPE_ROOM,
		IShare::TYPE_CIRCLE,
		IShare::TYPE_DECK,
	];

	/** @var array<string, array<int, list<IShare>>> shares by user id and file id */
	private array $shares = [];

	public function __construct(
		private readonly IManager $shareManager,
	) {
	}

	#[\Override]
	public function preload(NodeAttributeContext $context, array $nodes, array $attributes): void {
		$userId = $context->getUser()?->getUID();
		if ($userId === null) {
			return;
		}

		$nodes = array_filter($nodes, fn (Node $node): bool => !isset($this->shares[$userId][$node->getId()]));
		$folders = [];
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$folders[$node->getPath()] = $node;
			}
		}

		// Children of a folder in the same batch, a folder listing, are loaded with one call per folder
		$children = [];
		foreach ($nodes as $node) {
			$parentPath = dirname($node->getPath());
			if (isset($folders[$parentPath])) {
				$children[$parentPath][] = $node;
			}
		}
		foreach ($children as $parentPath => $folderChildren) {
			$folderShares = $this->shareManager->getSharesInFolder($userId, $folders[$parentPath], true);
			foreach ($folderChildren as $child) {
				$this->shares[$userId][$child->getId()] = array_values($folderShares[$child->getId()] ?? []);
			}
		}

		foreach ($nodes as $node) {
			if (!isset($this->shares[$userId][$node->getId()])) {
				$this->shares[$userId][$node->getId()] = $this->getShares($userId, $node);
			}
		}
	}

	#[\Override]
	public function getAttributes(NodeAttributeContext $context, Node $node, array $attributes): array {
		$userId = $context->getUser()?->getUID();
		if ($userId === null) {
			return [];
		}

		$this->preload($context, [$node], $attributes);
		$shares = $this->shares[$userId][$node->getId()];

		return [
			SharesPlugin::SHARETYPES_PROPERTYNAME => new NodeAttributeList(
				'share-type',
				array_values(array_unique(array_map(static fn (IShare $share): int => $share->getShareType(), $shares))),
			),
			SharesPlugin::SHAREES_PROPERTYNAME => new NodeAttributeList(
				'sharee',
				array_map(static fn (IShare $share): array => [
					'id' => $share->getSharedWith(),
					'display-name' => $share->getSharedWithDisplayName(),
					'type' => $share->getShareType(),
				], $shares),
			),
		];
	}

	/**
	 * Shares the user created on the node, and the ones the user received for it
	 *
	 * @return list<IShare>
	 */
	private function getShares(string $userId, Node $node): array {
		$shares = [];
		foreach (self::SHARE_TYPES as $shareType) {
			$shares[] = $this->shareManager->getSharesBy($userId, $shareType, $node, false, -1);
			try {
				$shares[] = $this->shareManager->getSharedWith($userId, $shareType, $node, -1);
			} catch (BackendError) {
				// a share provider that is not available has no shares to show
			}
		}
		return array_values(array_merge(...$shares));
	}
}
