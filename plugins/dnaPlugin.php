<?php

const DNA_REGEX = '([0-9]+)(:([0-9]+)(;([0-9]+))?)*::';

/**
 * When DNA gets posted in the channel, reply with a link to Osmium.
 */
class dnaPlugin implements pluginInterface {

	private $socket;

	/** Called when plugins are loaded */
	function init($config, $socket) {
		$this->socket = $socket;
	}

	/** Called about twice per second or when there are activity on
	 * the channel the bot are in. Put your jobs that needs to be run
	 * without user interaction here.
	 */
	function tick() {

	}

	/** Called when messages are posted on the channel the bot are in,
	 * or when somebody talks to it. */
	function onMessage($from, $channel, $msg) {
		if(!preg_match('%'.DNA_REGEX.'%', $msg, $matches) || strpos($msg, 'o.smium.org') !== false) {
			return;
		}

		$dna = $matches[0];
		$uri = 'http://o.smium.org/loadout/dna/'.$dna;
		$contents = file_get_contents($uri);
		if($contents === false) return;
		preg_match('%<title>([^<]+)</title>%', $contents, $matches);
		$title = explode(' / ', $matches[1], 2)[0];

		$tiny = file_get_contents('http://tinyurl.com/api-create.php?url='.$uri);
		if($tiny === false) return;

		sendMessage($this->socket, $channel, trim($title).': '.trim($tiny));
	}

	/** Called when the bot is shutting down */
	function destroy() {

	}

	/** Called when the server sends data to the bot which is *not* a
	 * channel message, useful if you want to have a plugin do it`s
	 * own communication to the server. */
	function onData($data) {

	}
}
