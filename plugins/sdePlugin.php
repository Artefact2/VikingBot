<?php

function dfs($src, $tgt, array $schema) {
	if($src === $tgt) return '';

	if(isset($schema[$tgt][$src])) {
		return $schema[$tgt][$src];
	}

	foreach($schema[$tgt] as $intermediate => $join) {
		unset($schema[$tgt][$intermediate]);
		$result = dfs($src, $intermediate, $schema);

		if($result !== false) return $result.' '.$join;
	}

	return false;
}

/**
 * Perform simple queries on the SDE.
 */
class sdePlugin implements pluginInterface {

	private $sqlite;
	private $trigger;
	private $socket;

	/** Called when plugins are loaded */
	function init($config, $socket) {
		$this->sqlite = new SQLite3($config['sde']['sqlitedb'], SQLITE3_OPEN_READONLY);
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
		if(!stringStartsWith($msg, "{$this->trigger}sde")) {
			return;
		}

		static $sources = [
			'type' => 'invtypes it',
			'attribute' => 'dgmattribs da',
			'effect' => 'dgmeffects de',
			'expression' => 'dgmexpressions dexp',
			'group' => 'invgroups ig',
		];

		$ors = implode('|', array_keys($sources));

		if($msg === '.sde help') {
			sendMessage($this->socket, $channel, $from.': .sde [<'.$ors.'>[s][(COL1,COL2,…)] of ]<'.$ors.'>[ ][<name|id>] NAME|ID');
			sendMessage($this->socket, $channel, $from.': .sde [COLUMN of ]<'.$ors.'>[ ][<name|id>] NAME|ID');
			return;
		}

		if(!preg_match(
			'%^'.preg_quote($this->trigger, '%').'sde '
			.'((((?<result>'.$ors.')s?(\((?<columns>[a-zA-Z.,]+)\))?)|(?<column>[a-z]+))\s+of\s+)?'
			.'(?<source>'.$ors.')(\s*(?<sourcetype>name|id))?\s+(?<sourceid>.+)'
			.'$%',
			$msg,
			$match
		)) {
			sendMessage($this->socket, $channel, $from.': invalid syntax.');
			return;
		}

		if(!$match['result']) {
			$match['result'] = $match['source'];
		}

		if(!$match['columns'] && $match['column']) {
			$col = $match['column'];
			$prefix = explode(' ', $sources[$match['result']], 2)[1];

			$match['columns'] = "{$prefix}.{$col}";
		}

		static $schema = [
			'type' => [
				'attribute' =>
				'JOIN dgmtypeattribs dta ON dta.typeid = it.typeid JOIN dgmattribs da ON da.attributeid = dta.attributeid',

				'effect' =>
				'JOIN dgmtypeeffects dte ON dte.typeid = it.typeid JOIN dgmeffects de ON de.effectid = dte.effectid',

				'group' =>
				'JOIN invgroups ig ON it.groupid = ig.groupid'
			],
			'effect' => [
				'expression' =>
				'JOIN dgmexpressions dexp ON dexp.expressionid IN (de.preexpression, de.postexpression)',

				'type' =>
				'JOIN dgmtypeeffects dte ON dte.effectid = de.effectid JOIN invtypes it ON it.typeid = dte.typeid',
			],
			'expression' => [
				'effect' =>
				'JOIN dgmeffects de ON dexp.expressionid IN (de.preexpression, de.postexpression)',
			],
			'attribute' => [
				'type' =>
				'JOIN dgmtypeattribs dta ON dta.attributeid = da.attributeid JOIN invtypes it ON it.typeid = dta.typeid',
			],
			'group' => [
				'type' =>
				'JOIN invtypes it ON it.groupid = ig.groupid'
			],
		];

		static $hccolumns = [
			'typeid' => 'it.typeid',
			'effectid' => 'de.effectid',
			'attributeid' => 'da.attributeid',
			'groupid' => 'ig.groupid',
			'expressionid' => 'dexp.expressionid',
		];

		if($match['columns']) {
			$statement = 'SELECT '.$match['columns'];
		}
		else $statement = 'SELECT *';

		$joins = dfs($match['source'], $match['result'], $schema);
		if($from === false) {
			sendMessage($this->socket, $channel, $from.': path not found.');
			return;
		}

		$prefix = explode(' ', $sources[$match['source']])[1];

		if(ctype_digit($match['sourceid'])) {
			$where = $prefix.'.'.$match['source'].'id = '.(int)$match['sourceid'];
		} else {
			$where = $prefix.'.'.$match['source']."name = '".SQLite3::escapeString($match['sourceid'])."'";
		}

		$statement .= ' FROM '.$sources[$match['result']]
			.' '.$joins." WHERE ".$where;

		var_dump($statement);
		@$q = $this->sqlite->query($statement);
		$rows = [];

		if($q instanceof SQLite3Result) {
			while($row = $q->fetchArray(SQLITE3_ASSOC)) {
				if(count($row) > 1) $rows[] = $row;
				else $rows[] = array_pop($row);
			}
		} else {
			sendMessage($this->socket, $channel, $from.': invalid query.');
			return;
		}

		if($rows === []) {
			sendMessage($this->socket, $channel, $from.': no results.');
			return;
		}

		if(is_array($rows[0])) {
			$reply = json_encode($rows);
		} else {
			$reply = implode(', ', $rows);
		}

		if(strlen($reply) < 150) {
			sendMessage($this->socket, $channel, $from.': '.$reply);
			return;
		} else {
			$reply = json_encode($rows, JSON_PRETTY_PRINT);
			$c = curl_init('http://dpaste.com/api/v1/');
			curl_setopt($c, CURLOPT_POST, true);
			curl_setopt($c, CURLOPT_POSTFIELDS, array('content' => $reply));
			curl_setopt($c, CURLOPT_HEADER, true);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			$r = curl_exec($c);

			preg_match("%^Location: (.+)$%m", $r, $match);
			$loc = trim($match[1]).'plain/';

			sendMessage($this->socket, $channel, $from.': '.count($rows).' row(s), '.$loc);
			return;
		}
	}

	/** Called when the bot is shutting down */
	function destroy() {
		$this->sqlite->close();
	}

	/** Called when the server sends data to the bot which is *not* a
	 * channel message, useful if you want to have a plugin do it`s
	 * own communication to the server. */
	function onData($data) {

	}
}
