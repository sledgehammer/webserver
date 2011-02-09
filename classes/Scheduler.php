<?php
/**
 * scheduler
 * Houd bij welke video's er converteerd moeten worden en start deze wanneer mogelijk.
 * Client functies (Zie convert.php voor het "background" process)
 */

class Scheduler extends Object {

	private
		$max_running,
		$auto_increment = 0,
		$tasks = array(),
		$stats = array();

	/**
 	 * @param int $max_running Het maximum aantal concurrent processen
	 */
	function __construct($max_running) {
		$this->max_running = $max_running;
	}

	function __destruct() {
		$tasks = $this->get_running_tasks();
		if (count($tasks) > 0) {
			$this->log('Waiting for '.count($tasks).' tasks to exit');
			while (true) {
				foreach ($tasks as $id => $task) {
					if ($this->is_completed($id)) {
						$tasks = $this->get_running_tasks();
						if (count($tasks) == 0) {
							break 2;
						} else {
							$this->log('Waiting for '.count($tasks).' tasks to exit');
						}
					}
				}
				sleep(1);
			} 
			$this->log('All tasks are completed');
		}
	}

	function getStats() {
		$stats = $this->stats;
		$counters = array();
		foreach ($this->tasks as $task) {
			@$stats[$task['state']]++;
		}
		return $stats;
	}

	function append($command) {
		$id = $this->generate_identifier();
		$this->tasks[$id] = array('command' => $command, 'state' => 'PENDING'); 
		$this->log('New task added: '.$id);
		// $this->schedule();
	}

	/**
	 * Start 
	 */
	function schedule() {
		//$this->log('scheduling');
		// Detect process exit & cleanup the task array
		foreach ($this->tasks as $id => $task) {
			switch ($task['state']) {

				case 'RUNNING':
					if ($this->is_completed($id)) {
						@$this->stats['COMPLETED']++;
						unset($this->tasks[$id]);
					}
					break;

				case 'COMPLETED':
				case 'ERROR':
					@$this->stats[$task['state']]++;
					unset($this->tasks[$id]);
					break;
			}
		}
		// Limit to N processes
		$running = count($this->get_running_tasks());
		if ($running >= $this->max_running) {
			return;
		}
		// Start pending tasks
		foreach ($this->tasks as $id => $task) {
			if ($task['state'] == 'PENDING') {
				if ($this->start($id)) {
					$running++;
					if ($running >= $this->max_running) {
						return;
					}
				}
			}
		}
	}

	private function start($identifier) {
		if (!isset($this->tasks[$identifier])) {
			notice('Task '.$identifier.' doesn\'t exist');
			return false;
		}
		$task = &$this->tasks[$identifier];
		$command = $task['command'];
		$task['state'] = 'STARTING';
		$this->log('Starting task: '.$identifier);
		$this->log($command);
		$proc = popen($command.' > /dev/null & echo $!', 'r');
		if ($proc === false) {
			$task['state'] = 'ERROR';
			$this->log('popen() failed: '.$identifier);
			return false;
		}
		$task['state'] = 'RUNNING';
		$task['process'] = $proc;
		$task['pid'] = fgets($proc);
		$task['start_ts'] = microtime(true);
		return true;
	}

	private function is_completed($identifier) {
		if (!isset($this->tasks[$identifier])) {
			notice('Task '.$identifier.' doesn\'t exist');
			return;
		}
		$task = &$this->tasks[$identifier];
		exec('ps '.$task['pid'], $output);
		$is_completed = count($output) < 2;
		if ($is_completed) {
			$task['state'] = 'COMPLETED';
			pclose($task['process']);
			$elapsed_time = microtime(true) - $task['start_ts'];
			if ($elapsed_time > 60) {
				$elapsed_string = floor($elapsed_time / 60).' min and '.round($elapsed_time % 60).' sec';
			} else {
				$elapsed_string = round($elapsed_time).' sec';
			}
			$this->log('Task '.$identifier.' completed in '.$elapsed_string);
		}
		return $is_completed;
	}

	private function get_running_tasks() {
		$running_processes = array();
		foreach ($this->tasks as $id => $task) {
			if ($task['state'] == 'RUNNING') {
				$running_processes[$id] = & $this->tasks[$id];
			}
		}
		return $running_processes;
	}

	private function generate_identifier() {
		$this->auto_increment++;
		return $this->auto_increment;
	}

	function log($message) {
		if (isset($GLOBALS['server']) && method_exists($GLOBALS['server'], 'log')) {
			$GLOBALS['server']->log($message);
		} else {
			echo date('[Y-m-d H:i:s]').get_class($this).'] '.$message."\n"; // Fallback
		}
	}
}
?>
