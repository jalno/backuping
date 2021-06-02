<?php
namespace packages\backuping\backupable;

use \InvalidArgumentException;
use packages\base\{IO\Directory as BaseDirectory};
use packages\backuping\{IBackupable, logging\Log};

class File implements IBackupable {

	public function backup(?Directory $repo, ?array $options = null) {
		$log = Log::getInstance();
		$log->info("start direc backup");

	}
	public function restore(Directory $repo, ?array $options = null) {
		$log = Log::getInstance();
		
	}
}