<?php
namespace packages\backuping;

use packages\base\{IO\Directory};

interface IBackupable {
	/**
	 * @param array $data
	 * @return IO\File|IO\Directory
	 */
	public function backup(array $options = array());

	/**
	 * @param IO\File|IO\Directory
	 * @param array $data
	 */
	public function restore(mixed $backup, array $options = array()): void;
}
