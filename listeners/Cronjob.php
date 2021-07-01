<?php
namespace packages\backuping\listeners;

use packages\cronjob\{task\Schedule, Task, events\Tasks};
use packages\backuping\processes;

class Cronjob {
	public function tasks(Tasks $event): void {
		$event->addTask($this->backupingBackup());
	}
	private function backupingBackup(): Task {
		$task = new Task();
		$task->name = 'backuping_backup';
		$task->process = processes\Backuping::class.'@backup';
		$task->parameters = array();
		$task->schedules = array(
			new Schedule(array(
				'minute' => 0,
				'hour' => 5,
			)),
		);
		return $task;
	}
}
