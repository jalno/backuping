<?php
namespace packages\backuping;

use packages\base\IO\Node;

/**
 * @phpstan-type MixedOptions array<string,mixed>
 */
interface IBackupable {
	/**
	 * @param MixedOptions $options
	 */
	public function backup(array $options = array()): Node;

	/**
	 * @param MixedOptions $options
	 */
	public function restore(Node $backup, array $options = array()): void;
}
