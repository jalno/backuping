<?php
namespace packages\backuping;

interface IBackupable {
	/**
	 * @param array $options
	 * @return \packages\base\IO\File|\packages\base\IO\Directory
	 */
	public function backup(array $options = array());

	/**
	 * @param \packages\base\IO\File|\packages\base\IO\Directory $backup
	 * @param array $options
	 */
	public function restore($backup, array $options = array()): void;
}
