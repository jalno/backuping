<?php
namespace packages\backuping\processes;

use \ZipArchive;
use \InvalidArgumentException;
use packages\base\{Log as BaseLog, IO, Options, Date, Process, Response};
use packages\backuping\{Backup, Log, Report};

class Backuping extends Process {

	protected ?bool $verbose = false;

	/**
	 * @param array<{"verbose": bool}> $data
	 */
	public function backup(array $data) {
		$this->prerun($data);
		$log = Log::getInstance();

		$log->info("get backup from sources...");
		foreach (Backup::getSources() as $source) {
			$log->info("get backup from source: ({$source->getID()})");
			$backupRepoForSource = new IO\Directory\TMP();
			try {
				$source->getBackupable()->backup($backupRepoForSource, $source->getOptions());
				$log->reply("done");

				$log->reply("make source working directory zip");
				$zipFileDir = new IO\Directory\TMP();
				$zipFileName = $source->getID() . "-" . Date::time() . ".zip";
				$log->reply("file name:", $zipFileName);
				$zipFile = $zipFileDir->file($zipFileName);
				$this->zipDirectoryToFile($backupRepoForSource, $zipFile);
				$log->reply("done");

				$log->info("transfer file to destinations");
				foreach (Backup::getDestinations() as $destination) {
					$destDir = $destination->getDirectory();
					$destFile = $destDir->file($zipFileName);
					$destFile->copyFrom($zipFile);
				}
				$log->reply("done");

			} catch (\Exception $e) {
				$log->error("error! message:", $e->getMessage(), "class:", get_class($e));
			}
		}

		$this->report(array(
			"subject" => "backup",
		));
	}

	/**
	 * @param array<{"verbose": bool, "sources": array<string>, "restore-backup-after": int}> $data
	 */
	public function restore(array $data) {
		$this->prerun($data);
		$log = Log::getInstance();

		$currentSourceIDs = array();
		foreach (Backup::getSources() as $source) {
			$currentSourceIDs[] = $source->getID();
		}

		$selectedSourceIDs = $data["sources"] ?? null;

		if ($selectedSourceIDs) {
			$selectedSourceIDs = is_array($selectedSourceIDs) ? $selectedSourceIDs : array($selectedSourceIDs);
			foreach ($selectedSourceIDs as $selectedSourceID) {
				if (!is_string($selectedSourceID)) {
					$log->error("the given source id ({$selectedSourceID}) is not valid!");
					throw new InvalidArgumentException("the given source id ({$selectedSourceID}) is not valid!");
				}
				if (!in_array($selectedSourceID, $currentSourceIDs)) {
					$log->error("the given source id ({$selectedSourceID}) is not exists!");
					throw new InvalidArgumentException("the given source id ({$selectedSourceID}) is not exists!");
				}
			}
			$log->info("try restore backup of sources:", $selectedSourceIDs);
		} else {
			$log->info("restore backup of all sources");
		}

		$findBackupAfter = $data["restore-backup-after"] ?? null;
		if (!$findBackupAfter) {
			$log->error("you should pass 'restore-backup-after' (int) arg to find the last backup after this time");
			throw new InvalidArgumentException("you should pass 'restore-backup-after' (int) arg to find the last backup after this time");
		}
		if (!is_numeric($findBackupAfter) or $findBackupAfter < 0) {
			$log->error("the 'find-backup-after' is not numeric value or not greater than zero! value: {$findBackupAfter}");
			throw new InvalidArgumentException("the 'restore-backup-after' is not numeric value or not greater than zero! value: {$findBackupAfter}");
		}


		$log->info("find the best backup of each source to restore");
		foreach (Backup::getSources() as $source) {
			$sourceID = $source->getID();
			$log->info("get backups of source: ({$sourceID}) from destinations");
			try {

				$findedBackups = array();
				$log->info("check each destinations");
				foreach (Backup::getDestinations() as $destination) {
					$directory = $destination->getDirectory();
					foreach ($directory->files(false) as $file) {
						if (
							substr($file->basename, 0, strlen($sourceID) + 1) == $sourceID . '-' and
							preg_match("/\\-(\d+)\\.zip$/", $file->basename, $matches)
						) {
							if ($matches[1] >= $findBackupAfter) {
								$findedBackups[$matches[1]] = $file;
							}
						}
					}
				}
				if ($findedBackups) {
					$log->info("find (" . count($findedBackups) . ") backup for source: ({$sourceID}), find last backup");
					$lastKey = max(array_keys($findedBackups));
					$backupFile = $findedBackups[$lastKey];

					$log->info("try restore backup: (" . $backupFile->getPath() . ") to source: ({$sourceID})");

					$zipFileDir = new IO\Directory\TMP();
					$log->info("try extract file to:", $zipFileDir->getPath());
					$this->extractZipFileToDirectory($backupFile, $zipFileDir);
					$log->reply("done");

					$log->info("call source ({$sourceID}) restore ...");
					$backupable = $source->getBackupable();
					$backupable->restore($zipFileDir, $source->getOptions());
					$log->reply("done");
				} else {
					$log->warn("can not find any backup for source: ({$sourceID}) after: ({$findBackupAfter})");
				}

			} catch (\Exception $e) {
				$log->error("error! message:", $e->getMessage(), "class:", get_class($e));
			}
		}

		$this->report(array(
			"subject" => "restore",
		));
	}

	/**
	 * @param array<{"verbose": bool, "sources": array<string>}> $data
	 */
	public function cleanup(array $data) {
		$this->prerun($data);
		$log = Log::getInstance();

		foreach (Backup::getSources() as $source) {
			$sourceID = $source->getID();
			$log->info("cleanup old backups of that related to source: ({$sourceID})");
			try {
				foreach (Backup::getDestinations() as $destination) {
					$lifetime = $destination->getLifeTime();
					if ($lifetime === null) {
						$log->warn("lifetime is null, so skip this destination...");
					}

					$shouldBeDeleted = array();

					$directory = $destination->getDirectory();
					foreach ($directory->files(false) as $file) {
						if (
							substr($file->basename, 0, strlen($sourceID) + 1) == $sourceID . '-' and
							preg_match("/\\-(\d+)\\.zip$/", $file->basename, $matches)
						) {
							if (Date::time() - $matches[1] > $lifetime * 86400) {
								$shouldBeDeleted[] = $file;
							}
						}
					}

					if ($shouldBeDeleted) {
						$log->info("find: (" . count($shouldBeDeleted) . ") to delete, try delete");
						foreach ($shouldBeDeleted as $rfile) {
							$log->info("delete file:", $rfile->getPath());
							if ($rfile->delete()) {
								$log->reply("done");
							} else {
								$log->reply("failed");
							}
						}
					} else {
						$log->info("this destination has no file to cleanup");
					}
				}
				$log->reply("done");

			} catch (\Exception $e) {
				$log->error("error! message:", $e->getMessage(), "class:", get_class($e));
			}
		}

		$this->report(array(
			"subject" => "cleanup",
		));
	}

	protected function report(?array $option) {
		$log = BaseLog::getInstance();

		$log->info("get report info");
		$info = Backup::getReportInfo();
		$log->reply("done", $info);

		if ($info and $info["sender"] and $info["receivers"]) {
			$log->info("prepare send email...");
			$messages = Log::getMessages();
			var_dump($messages);
			$report = new Report();
			$report->setSender($info["sender"]);
			$report->setSubject($info["subject"] . ((isset($option["subject"]) and $option["subject"]) ? " - " . $option["subject"] : ""));
			$report->setMessage(implode(PHP_EOL, $messages));
			foreach ($info["receivers"] as $receiver) {
				$report->addReceiver($receiver);
			}

			$log->info("send email...");
			$result = $report->send();
			$log->reply("result:", $result);

		} else {
			$log->warn("the report info is not set, so skip reporting");
		}
	}

	private function zipDirectoryToFile(IO\Directory\Local $zipDir, IO\File\Local $zipFile): void {
		$zip = new ZipArchive;
		if ($zip->open($zipFile->getPath(), ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
			foreach ($zipDir->files(true) as $file) {
				$zip->addFile($file->getPath(), $zipDir->getRelativePath($file));
			}
			$zip->close();
		} else {
			throw new Error("packages.backuping.processes.Backuping.error_zip_archive");
		}
	}

	private function extractZipFileToDirectory(IO\File\Local $zipFile, IO\Directory\Local $zipDir): void {
		$zip = new ZipArchive;
		if ($zip->open($zipFile->getPath())) {
			$zip->extractTo($zipDir->getPath());
			$zip->close();
		} else {
			throw new Error("packages.backuping.processes.Backuping.error_extract_zip");
		}
	}

	private function prerun(array $data): void {
		$this->verbose = $data["verbose"] ?? false;
		if ($this->verbose) {
			Log::setLevel("debug");
			BaseLog::setLevel("debug");
		}
		$log = BaseLog::getInstance();
		$log->info("load config");
		Backup::loadConfig();
		$log->reply("done");
	}
}
