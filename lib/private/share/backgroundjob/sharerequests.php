<?php

/**
 * ownCloud - send share messages as background job
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

namespace OC\Share\BackgroundJob;

use OC\DB\Connection;

class ShareRequests extends \OC\BackgroundJob\TimedJob {

	const WEB_CRON_BATCH_LIMIT = 2;
	const MAX_TRIES = 5;

	/** @var \OCP\IConfig */
	protected $config;

	/** @var Connection */
	protected $connection;

	public function __construct() {
		// Run every 15 minutes
		$this->setInterval(15 * 60);
		$this->config = \OC::$server->getConfig();
		$this->connection = \OC::$server->getDatabaseConnection();
	}

	protected function run($argument) {
		if (\OC::$CLI) {
			$this->runStep();
		} else {
			// send only 2 requests
			$this->runStep(self::WEB_CRON_BATCH_LIMIT);
		}
	}

	/**
	 * Send request
	 *
	 * @param int $limit Number of requests we want to send
	 */
	protected function runStep($limit = 0) {

		$requests = $this->executeDBQuery($limit);

		foreach ($requests as $request) {
			$data = json_decode($request['data'], true);
			if ($request['protocol'] === 'http://' || $request['protocol'] === 'https://') {
				$this->post($request['protocol'], $request['url'], $data, $request['uid']);
			} else {
				$this->tryProtocol($request['url'], $data, $request['uid']);
			}

			$this->updateRequest($request);
		}
	}

	/**
	 * select request we want to execute
	 *
	 * @param int $limit
	 * @return array
	 */
	protected function executeDBQuery($limit = 0) {

		$query = $this->connection->createQueryBuilder();
		$query->select('*')
			->from('`*PREFIX*share_mq`')
			->orderBy('`tries`', 'ASC');

		if ($limit !== 0) {
			$query->setMaxResults($limit);
		}

		$result = $query->execute();
		return $result->fetchAll();
	}

	/**
	 * update request in mq, either increase the number of 'tries' or remove it
	 * if the max. number of 'tries' is reached
	 *
	 * @param array $request
	 */
	protected function updateRequest(array $request) {
		$tries = (int) $request['tries'] + 1;
		$query = $this->connection->createQueryBuilder();
		if ($tries > self::MAX_TRIES) {
			$query->delete('`*PREFIX*share_mq`')
				->where($query->expr()->eq('`id`', $request['id']));
		} else {
			$query->update('`*PREFIX*share_mq`')
				->set('`tries`', $tries)
				->where($query->expr()->eq('`id`', $request['id']));
		}
		$query->execute();
	}

	/**
	 * send request
	 *
	 * @param string $protocol
	 * @param string $url
	 * @param array $data
	 * @param string $uid
	 * @return array
	 */
	protected function post($protocol, $url, $data, $uid) {
		$certificateManager = new \OC\Security\CertificateManager($uid, new \OC\Files\View());
		$httpHelper = new \OC\HTTPHelper($this->config, $certificateManager);
		return $httpHelper->post($protocol . $url, $data);
	}

	/**
	 * try https and http
	 *
	 * @param string $url
	 * @param array $data
	 * @param string $uid
	 * @return array
	 */
	protected function tryProtocol($url, array $data, $uid) {
		$protocol = 'https://';
		$success = false;
		$result = array();
		$try = 0;
		while ($success === false && $try < 2) {
			$result = $this->post($protocol, $url, $data, $uid);
			$success = $result['success'];
			$try++;
			$protocol = 'http://';
		}

		return $result;
	}

}
