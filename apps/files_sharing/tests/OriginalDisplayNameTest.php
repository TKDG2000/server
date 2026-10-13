<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Files_Sharing\Tests;

use OC\Files\Cache\CacheDependencies;
use OC\Files\View;
use OCA\Files_Sharing\SharedStorage;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class OriginalDisplayNameTest extends \Test\TestCase {
	private IShare&MockObject $share;

	protected function setUp(): void {
		parent::setUp();
		$this->share = $this->createMock(IShare::class);
	}

	public function testReturnsNullWhenTheNamesMatch(): void {
		$this->givenSourceName('Project-A');
		$this->share->method('getTarget')->willReturn('/Project-A');

		$this->assertNull($this->storage()->getOriginalDisplayName());
	}

	public function testReturnsTheSourceNameWhenASharerRenamedTheNode(): void {
		$this->givenSourceName('Project-A');
		$this->share->method('getTarget')->willReturn('/Shared/Project-A-with-Alice');

		$this->assertSame('Project-A', $this->storage()->getOriginalDisplayName());
	}

	public function testReturnsTheSourceNameForAnAutomaticConflictSuffix(): void {
		$this->givenSourceName('Project-A');
		$this->share->method('getTarget')->willReturn('/Project-A (1)');

		$this->assertSame('Project-A', $this->storage()->getOriginalDisplayName());
	}

	public function testUsesTheShareNodeWhenNoCacheEntryIsAvailable(): void {
		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn('Project-A');
		$this->share->method('getNodeCacheEntry')->willReturn(null);
		$this->share->method('getNode')->willReturn($node);
		$this->share->method('getTarget')->willReturn('/Project-A-with-Alice');

		$this->assertSame('Project-A', $this->storage()->getOriginalDisplayName());
	}

	public function testReturnsNullWhenTheSourceCannotBeResolved(): void {
		$this->share->method('getNodeCacheEntry')->willReturn(null);
		$this->share->method('getNode')->willThrowException(new NotFoundException());

		$this->assertNull($this->storage()->getOriginalDisplayName());
	}

	private function givenSourceName(string $name): void {
		$cacheEntry = $this->createMock(ICacheEntry::class);
		$cacheEntry->method('getName')->willReturn($name);
		$this->share->method('getNodeCacheEntry')->willReturn($cacheEntry);
		$this->share->expects($this->never())->method('getNode');
	}

	private function storage(): SharedStorage {
		return new SharedStorage([
			'ownerView' => $this->createMock(View::class),
			'logger' => $this->createMock(LoggerInterface::class),
			'shareManager' => $this->createMock(IShareManager::class),
			'cacheDependencies' => $this->createMock(CacheDependencies::class),
			'rootFolder' => $this->createMock(IRootFolder::class),
			'appConfig' => $this->createMock(IAppConfig::class),
			'superShare' => $this->share,
			'groupedShares' => [],
			'user' => 'recipient',
		]);
	}
}
