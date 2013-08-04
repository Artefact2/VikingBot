<?php
/**
 * Feed reader plugin, pulls specified RSS/Atom feeds at specified
 * intervalls and outputs changes to the specified channel.
 **/
class feedPlugin implements pluginInterface {

	private $socket;
	private $feedConfig;
	private $db;
	private $started;
	private $toecho;

	const DBFILE = './db/feedPlugin.db';

	function init($config, $socket) {
		$this->feedConfig = $config['plugins']['feedReader'];
		$this->socket = $socket;

		if(file_exists(self::DBFILE)) {
			$this->db = json_decode(file_get_contents(self::DBFILE), true);
		} else {
			$this->db = [];
		}

		$this->started = time();
		$this->toecho = array();
	}

	function onData($data) { }

	function tick() {
		if($this->started + 30 > time()) {
			return;
		}

		foreach($this->feedConfig as $feed) {
			$feeddata =& $this->db[$feed['name']];
			if(isset($feeddata['lastpoll']) && time() <= $feeddata['lastpoll'] + $feed['pollinterval']) {
				continue;
			}

			$feeddata['lastpoll'] = time();
			$raw = file_get_contents($feed['uri']);
			if($raw === false) continue;

			$entries = array();

			if($feed['type'] === 'atom') {
				try {
					$atom = new \SimpleXMLElement($raw);
					foreach($atom->entry as $entry) {
						$entries[] = array(
							'date' => strtotime((string)$entry->updated),
							'id' => (string)$entry->id,
							'title' => trim((string)$entry->title),
							'uri' => (string)$entry->link['href'],
						);
					}
				} catch(\Exception $e) {
					$entries = array();
				}
			}

			usort($entries, function($a, $b) {
				return $a['date'] - $b['date'];
			});

			$feeddata['entries'] = $entries;

			if((!isset($feeddata['lastdate']) || !isset($feeddata['lastid'])) && count($entries) > 0) {
				$last = $entries[count($entries) - 1];
				$feeddata['lastdate'] = $last['date'];
				$feeddata['lastid'] = $last['id'];
			}

			foreach($entries as $entry) {
				if(isset($feeddata['lastdate']) && $entry['date'] < $feeddata['lastdate']) continue;
				if(isset($feeddata['lastid']) && $entry['id'] === $feeddata['lastid']) continue;
				
				$feeddata['lastid'] = $entry['id'];
				$feeddata['lastdate'] = $entry['date'];

				sendMessage(
					$this->socket,
					$feed['channel'],
					"[".$feed['name']."] ".$entry['title']." ( ".$entry['uri']." )"
				);
			}

			file_put_contents(feedPlugin::DBFILE, json_encode($this->db));
		}
	}

	function onMessage($from, $channel, $msg) { }

	function destroy() { }
}
