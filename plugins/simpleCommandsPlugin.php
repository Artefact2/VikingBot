<?php

/**
 * Handle simple commands
 */
class simpleCommandsPlugin implements pluginInterface {
	private $socket;
	private $trigger;

	/** Called when plugins are loaded */
	function init($config, $socket) {
		$this->socket = $socket;
		$this->trigger = $config['trigger'];
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
		if(stringStartsWith($msg, "{$this->trigger}help")) {
			sendMessage(
				$this->socket, $channel, $from
				.': help, memory, ping, uptime, bc <expr>; prefix with '.$this->trigger
			);
			return;
		}

		if(stringStartsWith($msg, "{$this->trigger}bc")) {
			$command = escapeshellarg(substr($msg, strlen(($this->trigger)."bc")));
			$ret = shell_exec('echo '.$command.' | timeout 0.1 bc -lq 2>&1');
			sendMessage($this->socket, $channel, $from.': '.trim($ret));
			return;
		}
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
