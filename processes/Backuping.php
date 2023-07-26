<?php
namespace packages\backuping\processes;

use \ZipArchive;
use \InvalidArgumentException;
use packages\base\{Cli, Exception, Log as BaseLog, IO, Options, Date, Process, Response};
use packages\backuping\{Backup, IO\Directory\FilterableDirectory, Log, Report};

/**
 * @phpstan-type GeneralArgs array{verbose?: bool,report?: bool,sources?: string|string[],destinations?: string|string[]}
 */
class Backuping extends Process {

	protected bool $verbose = false;
	protected ?Backup $backup = null;

	/**
	 * @param GeneralArgs $data
	 */
	public function backup(array $data) {
		if (isset($data['verbose']) and $data['verbose']) {
			Log::setLevel("debug");
		}

		$log = Log::getInstance();

		$log->info("get backup from sources...");
		$sources = $this->getSources($data);

		foreach ($sources as $source) {
			$sourceID = $source->getID();
			$log->info("get backup from source: ({$sourceID})");

			$backupFileName = $sourceID . "-" . Date::time();

			try {
				$output = $source->getType()->backup($source->getOptions());

				if (!($output instanceof IO\File) and !($output instanceof IO\Directory)) {
					$error = "return value of backup should be instance of: '" . IO\File::class . "' or '" . IO\Directory::class . "' (" . gettype($output) . ") given!";
					$log->reply()->error($error);
					throw new Exception($error);
				}
				$log->reply("done");

				$log->info("check the output of backup source...");

				$fileForTransfer = $output;
				if ($output instanceof IO\Directory) {
					$backupFileName .= "-directory";

					$log->info("the output is a directory, so we should make it zip file");
					$tmpZip = new IO\File\TMP();
					$log->info("try zipping dir: ({$output->getPath()}) to file: ({$tmpZip->getPath()})");
					if (!$output instanceof IO\Directory\Local) {
						throw new Exception("currently only can zip local directory, [" . get_class($output) . "] given!");
					}
					$this->zipDirectory($output, $tmpZip);
					$log->reply("done, size:", $this->getHumanReadableSize($tmpZip->size()));

					if ($output instanceof IO\Directory\TMP) {
						$log->info("the output directory is a temp directory, so clean it to free space");
						$output->delete();
					}

					$fileForTransfer = $tmpZip;
					$backupFileName .= ".zip";
				}
				$log->info("file for transfer:", $backupFileName, "size:", $this->getHumanReadableSize($fileForTransfer->size()));

				$log->info("transfer file to destinations");
				$destinations = $this->getDestinations($data);
				foreach ($destinations as $destination) {
					$retries = $source->getTransferRetries();
					$log->debug("transfer retry is {$retries}");
					$log->info("try transfer backup of source: ({$sourceID}) to destination: ({$destination->getID()})");
					$successful = false;
					do {
						$log->debug("transfer retry is {$retries}");
						try {
							$directory = $destination->getDirectory();
							if (!$directory->exists()) {
								$directory->make(true);
							}
							$destFile = $directory->file($backupFileName);

							if ($fileForTransfer->copyTo($destFile)) {
								$successful = true;
								$log->reply("done!");

								$log->info("the backup is OK, so cleanup on backup?");
								if ($source->shouldCleanupOnBackup()) {
									$log->reply("yes");
									$cleanupData = array(
										"sources" => [$sourceID],
										"report" => false, // send all report at the end of backup instead of send chunk report
									);
									if (isset($data['destinations'])) {
										$cleanupData['destinations'] = $data['destinations'];
									}
									$this->cleanup($cleanupData);
								} else {
									$log->reply("no!");
								}
							} else {
								$log->reply("faild! copyTo return false!");
							}
						} catch (\Exception $e) {
							$log->reply()->error("failed! message: '" . $e->getMessage() . "' class:" . get_class($e), 'to string:', $e->__toString());
						}
					} while (!$successful and $retries-- > 0);
				}
				$log->info("remove ziped file ({$fileForTransfer->getPath()}) to free space");
				$fileForTransfer->delete();
			} catch (\Exception $e) {
				$log->error("error! message:", $e->getMessage(), "class:", get_class($e), 'to string:', $e->__toString());
			}
		}

		if (!isset($data["report"]) or $data["report"]) {
			$this->report(array(
				"subject" => "backup",
			));
		}
	}

	/**
	 * @param array{"verbose"?:bool,"report"?:bool,"sources"?:string|string[],"destination"?:string|string[],"backup-name"?:string,"restore-latest-backup"?:bool} $data
	 */
	public function restore(array $data) {
		if (isset($data['verbose']) and $data['verbose']) {
			Log::setLevel("debug");
		}

		$log = Log::getInstance();

		$sources = $this->getSources($data);

		$backupNameToRestore = $data["backup-name"] ?? null;
		if ($backupNameToRestore) {
			if (!is_string($backupNameToRestore)) {
				$log->error("the given 'backup-name' is not valid, it should be string!");
				throw new InvalidArgumentException("the given 'backup-name' is not valid, it should be string!");
			}
			$log->info("got 'backup-name': ({$backupNameToRestore}), so find backups by this name");
		}

		$latest = $data["restore-latest-backup"] ?? null;
		if ($backupNameToRestore and array_key_exists("restore-latest-backup", $data)) {
			$log->error("you can not pass 'backup-name' and 'restore-latest-backup' options at same time!");
		}

		$log->info("find the backups of each source from destinations to restore");
		foreach ($sources as $source) {
			$sourceID = $source->getID();
			$log->info("get backups of source: ({$sourceID}) from destinations");
			try {

				$findedBackups = array();
				$log->info("check each destinations");
				$destinations = $this->getDestinations($data);
				foreach ($destinations as $destination) {
					$destinationID = $destination->getID();
					$directory = $destination->getDirectory();
					foreach ($directory->files(false) as $file) {
						if (
							substr($file->basename, 0, strlen($sourceID) + 1) == $sourceID . '-' and
							preg_match("/\-(\d+)(-directory)?\.zip$/", $file->basename, $matches)
						) {
							$log->info("find backup for source: ({$sourceID}) on destination: ({$destinationID}), backup: ({$file->getPath()})");
							if ($backupNameToRestore and $file->basename != $backupNameToRestore) {
								$log->reply()->warn("the backup name: ({$file->basename}) is not equal to requested name: ({$backupNameToRestore}), so skip...");
								continue;
							}
							$log->reply("add to finded backups");
							$findedBackups[] = array(
								"file" => $file,
								"createAt" => $matches[1],
								"destinationID" => $destinationID,
								"isDirectory" => $matches[2] ?? false,
							);
						}
					}
				}
				if (empty($findedBackups)) {
					$log->warn("can not find any backup for source: ({$sourceID})");
					continue;
				}
				$countFindedBackups = count($findedBackups);
				$log->info("find ({$countFindedBackups}) backup for source: ({$sourceID})");

				$backupFile = null;

				if ($latest) {
					$log->info("you try to restore the latest backup! find it...");
					$latestCreateAt = max(array_column($findedBackups, "createAt"));
					$log->info("the last backup createAt is: ({$latestCreateAt})");
					foreach ($findedBackups as $findedBackup) {
						if ($findedBackup["createAt"] == $latestCreateAt) {
							$backupFile = $findedBackup;
							$log->info("the latest backup is on destination: " . $backupFile["destinationID"]);
						}
					}
				} elseif ($backupNameToRestore) {
					$log->info("you try to restore backup with name: ($backupNameToRestore)");
				}
				if ($countFindedBackups == 1) {
					$backupFile = $findedBackups[0];
				} else {
					$log->reply("more that on backup is found! you should select one of theme!");

					$x = 1;
					$answers = array();
					foreach ($findedBackups as $findedBackup) {
						$answers[$x] = "(" . $findedBackup["destinationID"] . "), basename: (" . $findedBackup["file"]->basename . "), date: (" . Date::format("Q QTS", $findedBackup["createAt"]) . ")";
						$x++;
					}
					$answers["skip"] = "skip...";
					$response = $this->askQuestion("What backup you want to restore?", $answers, true);
					if (strtolower($response) == "skip") {
						continue;
					} else {
						$response = (int) $response;
					}
					$backupFile = $findedBackups[$response - 1];
				}

				$log->info("try restore backup: (" . $backupFile["file"]->getPath() . ") from destination: (" . $backupFile["destinationID"] . ") to source: ({$sourceID})");

				$item = $backupFile["file"];
				if ($backupFile["isDirectory"]) {
					$log->info("backup is a zipped directory, so we should extract it!");

					$directory = new IO\Directory\TMP();
					$log->info("try extract file to:", $directory->getPath());
					$this->extractZipFile($backupFile["file"], $directory);
					$log->reply("done");
					$item = $directory;
				}
				$log->info("call source ({$sourceID}) restore ...");
				$type = $source->getType();
				$type->restore($item, $source->getOptions());
				$log->reply("done");

			} catch (\Exception $e) {
				$log->error("error! message:", $e->getMessage(), "class:", get_class($e), 'to string:', $e->__toString());
			}
		}

		if (!isset($data["report"]) or $data["report"]) {
			$this->report(array(
				"subject" => "restore",
			));
		}
	}

	/**
	 * @param GeneralArgs $data
	 */
	public function cleanup(array $data) {
		if (isset($data['verbose']) and $data['verbose']) {
			Log::setLevel("debug");
		}

		$log = Log::getInstance();

		$sources = $this->getSources($data);

		foreach ($sources as $source) {
			$sourceID = $source->getID();
			$log->info("cleanup old backups of that related to source: ({$sourceID})");
			try {
				$destinations = $this->getDestinations($data);
				foreach ($destinations as $destination) {
					$destinationID = $destination->getID();
					$log->info("destination ID: ({$destinationID})");
					$lifetime = $destination->getLifeTime();
					if ($lifetime === null) {
						$log->warn("lifetime is null, so skip this destination...");
						continue;
					}
					$log->info("the lifetime of destination ($destinationID) is ({$lifetime}) day");

					$directory = $destination->getDirectory();
					$log->info("find backup file of source: {$sourceID} on destination: {$destinationID}");

					$backupFiles = array();
					foreach ($directory->files(false) as $file) {
						if (preg_match("/^" . preg_quote($sourceID) . "-(\d+)(-directory)?\.zip$/", $file->basename, $matches)) {
							$backupFiles[$matches[1]] = $file;
						}
					}

					$backupFilesCount = count($backupFiles);
					$log->reply("count:", $backupFilesCount);

					if (empty($backupFiles)) {
						$log->warn("no backup found, skip");
						continue;
					}

					$minimumBackups = $source->getMinKeepingBackupCount();
					if ($backupFilesCount <= $minimumBackups) {
						$log->warn("skip, minimum backup of this source is: {$minimumBackups}");
						continue;
					}

					// sort backups ASC, so older backups comes first
					ksort($backupFiles);

					$shouldBeDeleted = array();
					foreach ($backupFiles as $backupAt => $file) {
						if ((Date::time() - intval($backupAt) > $lifetime * 86400) and count($shouldBeDeleted) <= $backupFilesCount - $minimumBackups) {
							$shouldBeDeleted[] = $file;
						}
					}

					if (empty($shouldBeDeleted)) {
						$log->info("this destination has no file to cleanup");
						continue;
					}

					$log->info("find: (" . count($shouldBeDeleted) . ") to delete, try delete");
					foreach ($shouldBeDeleted as $file) {
						$log->info("delete file:", $file->getPath());
						try {
							$file->delete();
							$log->reply("done");
						} catch (IO\Exception $e) {
							$log->reply("failed, message:", $e->getMessage(), "class:", get_class($e), 'to string:', $e->__toString());
						}
					}
				}

			} catch (\Exception $e) {
				$log->error("error! message:", $e->getMessage(), "class:", get_class($e), 'to string:', $e->__toString());
			}
		}

		if (!isset($data["report"]) or $data["report"]) {
			$this->report(array(
				"subject" => "cleanup",
			));
		}
	}

	public function help(array $data) {
		echo PHP_EOL;
		echo "Usage: backup|cleanup|restore [OPTIONS]" . PHP_EOL;
		echo PHP_EOL . "Backuping: a dynamic tool for get backup from anything!" . PHP_EOL;

		echo PHP_EOL;
		echo "Commands:" . PHP_EOL;
		echo "\t" . "backup [--verbose] [--sources=<source-id>] [--destinations=<destination-id>]" . PHP_EOL;
		echo "\t" . "clanup [--verbose] [--sources=<source-id>] [--destinations=<destination-id>]" . PHP_EOL;
		echo "\t" . "restore [--verbose] [--sources=<source-id>] [--destinations=<destination-id>] [--backup-name] [--report]" . PHP_EOL;

		echo PHP_EOL;
		echo "Global Options:" . PHP_EOL;
		echo "\t" . "--verbose" . "\t\t\t" . "print the logs for debug purposes" . PHP_EOL;
		echo "\t" . "--report" . "\t\t\t" . "pass to send report of the action to email" . PHP_EOL;
		echo "\t" . "--sources=<source-id>" . "\t\t" . "is the id of source you defined in config (may be specified multiple times)" . PHP_EOL;
		echo "\t" . "--destinations=<destination-id>\tis the id of source you defined in config (may be specified multiple times)" . PHP_EOL;

		echo PHP_EOL;
		echo "Restore Options:" . PHP_EOL;
		echo "\t" . "--backup-name" . "\t\t\t" . "the name of the abckup file you want to restore" . PHP_EOL;
		echo "\t" . "--restore-latest-backup" . "\t\t" . "restore the last backup file" . PHP_EOL;
		echo PHP_EOL;

		echo "Sources:" . PHP_EOL;
		$sources = $this->getSources($data);
		foreach ($sources as $source) {
			echo "\t" . "'{$source->getID()}'" . PHP_EOL;
		}
		echo PHP_EOL;

		echo "Destinations:" . PHP_EOL;
		$destinations = $this->getDestinations($data);
		foreach ($destinations as $destination) {
			echo "\t" . "'{$destination->getID()}'" . PHP_EOL;
		}
		echo PHP_EOL;
	}

	protected function report(?array $option) {
		if (isset($option['verbose']) and $option['verbose']) {
			BaseLog::setLevel("debug");
		}

		$log = BaseLog::getInstance();

		$log->info("get report info");
		$info = $this->getBackup()->getReportInfo();
		$log->reply("done", $info);

		if ($info and $info["sender"] and $info["receivers"]) {
			$log->info("prepare send email...");
			$messages = Log::getMessages();
			$report = new Report();
			$report->setMailer($info["sender"]["type"] ?? "mail", $info["sender"]["options"] ?? []);
			if (isset($info["sender"]["from"], $info["sender"]["from"]["address"])) {
				$report->setFrom($info["sender"]["from"]["address"], $info["sender"]["from"]["name"] ?? "");
			}
			$report->setSubject($info["subject"] . ((isset($option["subject"]) and $option["subject"]) ? " - " . $option["subject"] : ""));
			$report->setMessage(implode(PHP_EOL, $messages));
			foreach ($info["receivers"] as $receiver) {
				$report->addReceiver($receiver);
			}

			$log->info("send email...");
			$result = $report->send();
			if ($result) {
				$log->reply("result:", $result);
			} else {
				$log->reply()->warn("an error in send email!");
			}

		} else {
			$log->warn("the report info is not set, so skip reporting");
		}
	}

	protected function zipDirectory(IO\Directory\Local $zipDir, IO\File\Local $zipFile): void {
		$log = Log::getInstance();

		$files = $zipDir->files(true);
		if (empty($files)) {
			$log->info("there is not file in directory [{$zipDir->getPath()}] to compress, make empty zip");
			// this is empty zip file!
			$zipFile->write(base64_decode("UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA=="));
			return;
		}
		$zip = new ZipArchive;
		if (!$zip->open($zipFile->getPath(), ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
			$message = sprintf('can not open ZipArchive, message: [%s] in path: [%s]', $zip->getStatusString(), $zipFile->getPath());
			$log->error($message);
			unset($zip);
			throw new Exception($message);
		}
		foreach ($files as $file) {
			$log->debug("check file [{$file->getPath()}] to add in archive");

			$relativePath = $file->getRelativePath($zipDir);
			$log->reply("relative path is [{$relativePath}]");
			$zip->addFile($file->getPath(), $relativePath);

			/** prevent compress already compressed files! */
			$ext = $file->getExtension();
			if (in_array($ext, array('zip', 'gz', 'tar.gz', 'zst', 'tar.zst'))) {
				$zip->setCompressionName($relativePath, ZipArchive::CM_STORE);
			}
		}
		if (!$zip->close()) {
			$message = sprintf('can not close ZipArchive, message: [%s] in path: [%s]', $zip->getStatusString(), $zipFile->getPath());
			$log->error($message);
			unset($zip);
			throw new Exception($message);
		}
	}

	protected function extractZipFile(IO\File $zipFile, IO\Directory\Local $zipDir): void {
		$log = Log::getInstance();
		$log->info("check file is on local?");

		if (!($zipFile instanceof IO\File\Local)) {
			$log->info("is not on local!, copy it on local");
			$tmpFile = new IO\File\TMP();
			if ($zipFile->copyTo($tmpFile)) {
				$log->reply("done, local file: " . $tmpFile->getPath());
				$zipFile = $tmpFile;
			} else {
				$log->reply()->error("can not copy file: (" . get_class($zipFile) . "):{$zipFile->getPath()}) to local!");
				throw new Exception("can not copy file: (" . get_class($zipFile) . "):{$zipFile->getPath()}) to local!");
			}
		} else {
			$log->reply("is on local");
		}

		$zip = new ZipArchive;
		if (!$zip->open($zipFile->getPath())) {
			throw new Exception("packages.backuping.processes.Backuping.error_extract_zip");
		}
		$zip->extractTo($zipDir->getPath());
		if ($zipFile instanceof IO\File\TMP) {
			unset($zipFile);
		}
		if (!$zip->close()) {
			throw new Exception("packages.backuping.processes.Backuping.error_close_zip_archive");
		}
	}

	protected function getSources(array $data): array {
		if (isset($data['verbose']) and $data['verbose']) {
			Log::setLevel("debug");
		}

		$log = Log::getInstance();

		$allSources = $this->getBackup()->getSources();

		$selectedSourceIDs = $data["sources"] ?? null;
		if (!$selectedSourceIDs) {
			$log->info("no sources is given, so use all sources");
			return $allSources;
		}

		if (!is_array($selectedSourceIDs)) {
			$selectedSourceIDs = array($selectedSourceIDs);
		}

		$sources = array();
		foreach ($selectedSourceIDs as $sourceID) {
			if (!is_string($sourceID)) {
				$log->error("the given source id ({$sourceID}) is not valid!");
				throw new InvalidArgumentException("the given source id ({$sourceID}) is not valid!");
			}
			foreach ($allSources as $source) {
				if ($source->getID() == $sourceID) {
					$sources[] = $source;
					continue 2;
				}
			}
			$log->error("the given source id ({$sourceID}) is not exists!");
			throw new InvalidArgumentException("the given source id ({$sourceID}) is not exists!");
		}
		$log->info("selected source ids:", $selectedSourceIDs);
		return $sources;
	}

	protected function getDestinations(array $data): array {
		if (isset($data['verbose']) and $data['verbose']) {
			Log::setLevel("debug");
		}

		$log = Log::getInstance();
		
		$allDestionations = $this->getBackup()->getDestinations();

		$selectedDestinationIDs = $data["destinations"] ?? null;
		if (!$selectedDestinationIDs) {
			$log->info("no destinations is given, so use all destinations");
			return $allDestionations;
		}

		if (!is_array($selectedDestinationIDs)) {
			$selectedDestinationIDs = array($selectedDestinationIDs);
		}

		$destionations = array();
		foreach ($selectedDestinationIDs as $destinationID) {
			if (!is_string($destinationID)) {
				$log->error("the given destination id ({$destinationID}) is not valid!");
				throw new InvalidArgumentException("the given destination id ({$destinationID}) is not valid!");
			}
			foreach ($allDestionations as $destionation) {
				if ($destionation->getID() == $destinationID) {
					$destionations[] = $destionation;
					continue 2;
				}
			}
			$log->error("the given destination id ({$destinationID}) is not exists!");
			throw new InvalidArgumentException("the given destination id ({$destinationID}) is not exists!");
		}
		$log->info("selected destination ids:", $selectedDestinationIDs);
		return $destionations;
	}

	protected function getHumanReadableSize(int $size): string {
		$base = log($size, 1024);
		$suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
		return round(pow(1024, $base - floor($base)), 2) .' '. $suffixes[floor($base)];
	}

	private function getBackup()
	{
		if (!$this->backup) {
			$this->backup = new Backup();
		}

		return $this->backup;
	}

	private function askQuestion(string $question, ?array $answers = null, bool $showAnswersOnNewLine = false): string {
		do {
			$helpToAsnwer = "";
			if ($answers) {
				foreach ($answers as $shortcut => $answer) {
					if ($helpToAsnwer) {
						$helpToAsnwer .= $showAnswersOnNewLine ? "\n" : ", ";
					}
					$shutcut = strtoupper($shortcut);
					$helpToAsnwer .= ($answer != $shutcut ? $shortcut . " = " : "") . $answer;
				}
			}
			if ($helpToAsnwer) {
				$helpToAsnwer = $showAnswersOnNewLine ? "\n$helpToAsnwer\n" : "[{$helpToAsnwer}]";
			}
			$response = Cli::readLine($question . ($helpToAsnwer ? $helpToAsnwer : "") . ": ");
			if ($answers) {
				$response = strtoupper($response);
				$shutcuts = array_map('strtoupper', array_keys($answers));
				if (in_array($response, $shutcuts)) {
					return $response;
				}
			} elseif ($response) {
				return $response;
			}
		} while(true);
	}
}
