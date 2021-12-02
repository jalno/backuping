<?php
namespace packages\backuping\backupables;

use \InvalidArgumentException;
use packages\base\{IO\File, IO};
use packages\backuping\{IBackupable, Log};
use packages\finder\Finder;

class Directory implements IBackupable {

	public function backup(array $options = array()) {
		$log = Log::getInstance();
		$log->info("start directory backup");

		$directory = $options["directory"] ?? null;
		if (!$directory) {
			$log->error("you should pass 'directory' in options to make a backup of it!");
			throw new InvalidArgumentException("you should pass 'directory' in options to make a backup of it!");
		}
		if (is_callable($directory)) {
			$log->info("directory is callable, so call it");
			$directory = $directory($options);
		}
		if (!($directory instanceof IO\Directory)) {
			$log->error("the given 'directory' is not valid! it should be instance of:" . IO\Directory::class . " (" . gettype($directory) . ") given!");
			throw new InvalidArgumentException("the given 'directory' is not valid! it should be instance of:" . IO\Directory::class .  " (" . gettype($directory) . ") given!");
		}

		$excludes = $options["exclude"] ?? [];
		if (!is_array($excludes)) {
			$log->error("the 'exclude' index should be array of string (literal string or regex)");
			throw new InvalidArgumentException("the 'exclude' index should be array of string (literal string or regex)");
		}
		foreach ($excludes as $exclude) {
			if (!is_string($exclude)) {
				$log->error("the 'exclude' items should be string! (literal or regex)");
				throw new InvalidArgumentException("the 'exclude' items should be string! (literal or regex)");
			}
		}
		$log->info("excludes:", $excludes);

		$log->info("check directory...");
		$result = new IO\Directory\TMP();
		if (!$result->exists()) {
			$result->make(true);
		}

		$finder = (new Finder)->in($directory->getPath());
		$finder->notPath($excludes);

		foreach ($finder as $splFile) {
			$item = $splFile->getJalnoIO();
			if (!$item) {
				$log->warn('The item:', $splFile->getPathName(), 'is not file or directory, so skip');
				continue;
			}
			$log->info("add item:", $item->getPath());
			$relativePath = $item->getRelativePath($directory);
			if ($item instanceof IO\File) {
				$file = $result->file($relativePath);
				$fileDir = $file->getDirectory();
				if (!$fileDir->exists()) {
					$fileDir->make(true);
				}
				$item->copyTo($file);
			}
		}

		return $result;
	}
	public function restore($repo, array $options = array()): void {
		$log = Log::getInstance();
		
		$directory = $options["directory"] ?? null;
		if (!$directory) {
			$log->error("you should pass 'directory' in options to restore backup to it!");
			throw new InvalidArgumentException("you should pass 'directory' in options to restore backup to it!");
		}
		if (is_callable($directory)) {
			$log->info("directory is callable, so call it");
			$directory = $directory($options);
		}
		if (!($directory instanceof IO\Directory)) {
			$log->error("the given 'directory' is not valid! it should be instance of:" . IO\Directory::class);
			throw new InvalidArgumentException("the given 'directory' is not valid! it should be instance of:" . IO\Directory::class);
		}

		foreach ($repo->items(false) as $item) {
			$log->debug("item:", $item->basename);
			$relativePath = $item->getRelativePath($repo);
			if ($item instanceof IO\File) {
				$file = $directory->file($relativePath);
				$item->copyTo($file);
			} else {
				$dir = $directory->directory($relativePath);
				$item->copyTo($dir);
			}
		}
	}

	protected function isMatch(array $patterns, string $subject): bool {
		foreach ($patterns as $pattern) {
			if (preg_match("/{$pattern}/", $subject)) {
				return true;
			}
		}
		return false;
	}
}