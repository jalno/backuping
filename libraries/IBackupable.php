<?php
namespace packages\backuping;

use packages\base\{IO\Directory};

interface IBackupable {
	public function backup(Directory $repo, ?array $options = null);
	public function restore(Directory $repo, ?array $options = null);
}
