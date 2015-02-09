<?php

/**
 * ownCloud
 *
 * @copyright (C) 2015 ownCloud, Inc.
 *
 * @author Bjoern Schiessle <schiessle@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

class ShareRequestsTest extends \Test\TestCase {

	/** @var \OC\Share\BackgroundJob\ShareRequests */
	protected $shareRequests;

	protected function setUp() {
		parent::setUp();
		$this->shareRequests = new \OC\Share\BackgroundJob\ShareRequests();
		for ($i = 0; $i < 10; $i++) {
			$this->assertTrue(
				$this->populateShareQueue('www.owncloud.org', array('token' => $i), 'user1', 'https://', $i % 6)
			);
		}
	}

	protected function tearDown() {
		$query = \OCP\DB::prepare('DELETE FROM `*PREFIX*share_mq`');
		$query->execute();
		parent::tearDown();
	}

	public function testExecuteDBQuerry() {
		$all = \Test_Helper::invokePrivate($this->shareRequests, 'executeDBQuery');
		$this->assertSame(10, count($all));

		// We expect the 5 open request with the lowest number of tries
		// in this case 2x 0tries; 2*1tries; 1*2tries
		$first5 = \Test_Helper::invokePrivate($this->shareRequests, 'executeDBQuery', array(5));
		$this->assertSame(5, count($first5));
		foreach ($first5 as $r) {
			$this->assertTrue((int) $r['tries'] <= 2);
		}
	}

	public function testUpdateRequest() {
		$allBeforeUpdates = \Test_Helper::invokePrivate($this->shareRequests, 'executeDBQuery');

		// each try should increase 'tries' by one
		$triesBeforeUpdate = (int) $allBeforeUpdates[0]['tries'];
		$updatedId = $allBeforeUpdates[0]['id'];
		$this->assertSame(0, $triesBeforeUpdate);
		\Test_Helper::invokePrivate($this->shareRequests, 'updateRequest', array($allBeforeUpdates[0]));

		// after 5 tries the request should be removed from the queue
		$triesBeforeRemove = (int) $allBeforeUpdates[9]['tries'];
		$deletedId = $allBeforeUpdates[9]['id'];
		$this->assertSame(5, $triesBeforeRemove);
		\Test_Helper::invokePrivate($this->shareRequests, 'updateRequest', array($allBeforeUpdates[9]));

		$allAfterUpdates = \Test_Helper::invokePrivate($this->shareRequests, 'executeDBQuery');
		// one request should be deleted
		$this->assertSame(9, count($allAfterUpdates));

		// verify the updated request
		$updatedRequest = $this->getRequestWithId($allAfterUpdates, $updatedId);
		$this->assertSame($triesBeforeUpdate + 1, (int) $updatedRequest['tries']);

		// verify that the deleted request no longer exists
		$deletedRequest = $this->getRequestWithId($allAfterUpdates, $deletedId);
		$this->assertNull($deletedRequest);
	}

	protected function getRequestWithId($requests, $id) {
		$result = null;
		foreach ($requests as $r) {
			if ($r['id'] === $id) {
				$result = $r;
				break;
			}
		}
		return $result;
	}

	protected function populateShareQueue($url, $data, $uid, $protocol, $tries) {
		$statement = 'INSERT INTO `*PREFIX*share_mq` (`url`, `data`, `protocol`, `uid`, `tries`) VALUES(?, ?, ?, ?, ?)';
		$query = \OCP\DB::prepare($statement);
		$result = $query->execute(array($url, json_encode($data), $protocol, $uid, $tries));
		return $result === 1 ? true : false;
	}

}
