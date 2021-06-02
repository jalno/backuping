<?php
namespace packages\backuping\processes;

use \ZipArchive;
use packages\base\{Log as BaseLog, IO, Options, Date, Process, Response};
use packages\backuping\{Backup, Log, Report};

class Backuping extends Process {

	protected ?bool $verbose = false;
	protected ?bool $dryRun = false;

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
				$this->localDirZipMaker($backupRepoForSource, $zipFile);
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

		$this->report();
	}

	public function restore(array $data) {
		$this->prerun($data);
		$log = BaseLog::getInstance();
	}

	public function cleanup(array $data) {
		$this->prerun($data);
		$log = BaseLog::getInstance();

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
	}

	protected function report() {
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
			$report->setSubject($info["subject"]);
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

	private function localDirZipMaker(IO\Directory\Local $zipDir, IO\File\Local $zipFile): void {
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

	private function prerun(array $data): void {
		$this->dryRun = $data["dry-run"] ?? false;
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
