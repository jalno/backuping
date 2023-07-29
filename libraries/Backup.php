<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{view\Error, IO, Log, Options};

class Backup {
	public const CONFIG_OPTION_NAME = "packages.backuping.config";

	protected string $optionName;
	protected bool $configLoaded = false;
	protected array $config = array(
		"sources" => null,
		"destinations" => null,
		"report" => null,
	);
	private array $option = [];

	public function __construct(string $optionName = self::CONFIG_OPTION_NAME) {
		$this->optionName = $optionName;
	}

	/**
	 * @return Source[]
	 */
	public function getSources(bool $reload = false): array
	{
		if (is_null($this->config["sources"]) or $reload) {
			$log = Log::getInstance();

			$this->config["sources"] = [];

			$option = $this->getOption();

			$globalOptions = $option["options"] ?? null;
			$log->info("main 'options':", $globalOptions);

			if ($globalOptions) {
				if (!is_array($globalOptions)) {
					$log->reply()->error("options is not array!");
					throw new Error("packages.backuping.Backup.options_is_not_array");
				}

				if (isset($globalOptions["cleanup_on_backup"])) {
					Source::setGlobalCleanupOnBackup(boolval($globalOptions["cleanup_on_backup"]));
					$log->info("Source::globalShouldCleanupOnBackup is:", Source::globalShouldCleanupOnBackup() ? "true" : "false");
				}

				if (isset($globalOptions["minimum_keeping_source_backups"])) {
					if (!is_numeric($globalOptions["minimum_keeping_source_backups"])) {
						$log->reply()->error("the 'minimum_keeping_source_backups' is not numeric!");
						throw new Error("packages.backuping.Backup.minimum_keeping_source_backups.is_not_numeric");
					} elseif ($globalOptions["minimum_keeping_source_backups"] < 0) {
						$log->reply()->error("the 'minimum_keeping_source_backups' should zero or higher!");
						throw new Error("packages.backuping.Backup.minimum_keeping_source_backups.is_smaller_than_zero");
					}
					$log->info("the 'minimum_keeping_source_backups' is set to:", $globalOptions["minimum_keeping_source_backups"]);
					Source::setGlobalMinKeepingBackupsCount($globalOptions["minimum_keeping_source_backups"]);
				}

				if (isset($globalOptions["transfer_source_backup_retries"])) {
					if (!is_numeric($globalOptions["transfer_source_backup_retries"])) {
						$log->reply()->error("the 'transfer_source_backup_retries' is not numeric!");
						throw new Error("packages.backuping.Backup.transfer_source_backup_retries.is_not_numeric");
					} elseif ($globalOptions["transfer_source_backup_retries"] < 0) {
						$log->reply()->error("the 'transfer_source_backup_retries' should zero or higher!");
						throw new Error("packages.backuping.Backup.transfer_source_backup_retries.is_smaller_than_zero");
					}
					$log->info("the 'transfer_source_backup_retries' is set to:", $globalOptions["transfer_source_backup_retries"]);
					Source::setTransferRetries($globalOptions["transfer_source_backup_retries"]);
				}
			}

			$log->info("try prepare sources...");
			$sources = $option["sources"] ?? null;

			if (!$sources) {
				$log->reply()->error("sources is empty!");
				throw new Error("packages.backuping.Backup.sources_is_empty");
			}

			if (!is_array($sources)) {
				$log->error("the given 'sources' is not array!");
				throw new InvalidArgumentException("the given sources should be array");
			}

			foreach ($sources as $sourceArray) {
				$this->addSource(Source::fromArray($sourceArray));
			}
		}

		return $this->config["sources"];
	}

	public function addSource(Source $src): void
	{
		$id = $src->getID();
		$sourcesIDs = array_map(fn(Source $s) => $s->getID(), $this->getSources());
		if (in_array($id, $sourcesIDs)) {
			throw new Error("packages.backuping.Backup.source_id_is_duplicate");
		}

		$this->config["sources"][] = $src;
	}

	/**
	 * @return Destination[]
	 */
	public function getDestinations(bool $reload = false): array
	{
		if (is_null($this->config["destinations"]) or $reload) {
			$log = Log::getInstance();

			$this->config["destinations"] = [];

			$option = $this->getOption();

			$log->info("try prepare destinations...");
			$destinations = $option["destinations"] ?? null;
			if (!$destinations) {
				$log->reply()->error("destinations is empty!");
				throw new Error("packages.backuping.Backup.destinations_is_empty");
			}

			if (!is_array($destinations)) {
				$log->error("the given 'destinations' is not array!");
				throw new InvalidArgumentException("the given destinations should be array");
			}

			foreach ($destinations as $destinationArray) {
				$this->addDestination(Destination::fromArray($destinationArray));
			}
		}

		return $this->config["destinations"];
	}

	public function addDestination(Destination $dst): void
	{
		$id = $dst->getID();
		$destinationsIDs = array_map(fn(Destination $d) => $d->getID(), $this->getDestinations());
		if (in_array($id, $destinationsIDs)) {
			throw new Error("packages.backuping.Backup.destination_id_is_duplicate");
		}
		$this->config["destinations"][] = $dst;
	}

	/**
	 * @return array{subject?:string,sender?:array{type?:string,options?:array{host:string,port:int}},receivers?:string[]}
	 */
	public function getReportInfo(bool $reload = false): array
	{
		if (is_null($this->config['report']) or $reload) {
			$log = Log::getInstance();
			
			$this->config['report'] = [
				"subject" => null,
				"sender" => null,
				"receivers" => null,
			];

			$option = $this->getOption();

			$log->info("try prepare report");
			$report = $option["report"] ?? null;
			if (!$report) {
				$log->warn("the report is empty, it seems no need to report");
				$this->config['report'] = [];
			} else {
				$subject = $report["subject"] ?? "Backuping Report";
				if (!is_string($subject)) {
					$log->error("report subject should be string");
					throw new InvalidArgumentException("report subject should be string");
				}
				$this->config["report"]["subject"] = $subject;

				$sender = $report["sender"] ?? null;
				if (!$sender or !is_array($sender)) {
					$log->error("you should pass email sender as array with 'email' (and 'name') index or remove report index to skip report");
					throw new InvalidArgumentException("you should pass email sender as array with 'email' (and 'name') index or remove report index to skip report");
				}

				$sender["type"] = isset($sender["type"]) ? strtolower(trim($sender["type"])) : null;

				if (empty($sender["type"])) {
					$log->info("the 'type' of sender is not passed, so we use 'mail' as sender");
				} elseif (!in_array($sender["type"], ["mail", "smtp"])) {
					$log->error("the sender['type'] index is not valid, currently only support 'mail' and 'smtp'");
					throw new InvalidArgumentException("you should pass email sender as array with 'email' (and 'name') index or remove report index to skip report");
				} elseif ($sender["type"] == 'smtp') {
					$sender['options'] = is_array($sender['options']) ? $sender['options'] : [];
					if (!isset($sender['options']['host'])) {
						$log->error("you choosed 'smtp' driver to send email, but not gived ['options']['host'], check against your config");
						throw new InvalidArgumentException("you choosed 'smtp' driver to send email, but not gived 'host' index in 'options', check against your config");
					}
					if (!isset($sender['options']['port']) or empty($sender['options']['port'])) {
						$log->warn("the port is not set or is empty, so we use default 25");
					} elseif (!is_numeric($sender['options']['port'])) {
						$log->error("the given ['options']['port'] is not numeric!, check against your config!");
						throw new InvalidArgumentException("the given ['options']['port'] is not numeric!, check against your config!");
					}
				}

				$this->config["report"]["sender"] = $sender;

				$receivers = $report["receivers"] ?? [];
				if (!$receivers) {
					$log->error("you should add receivers to report is sendable");
					throw new InvalidArgumentException("you should add receivers to report is sendable");
				}

				$this->config["report"]["receivers"] = $receivers;
			}
		}

		return $this->config["report"];
	}

	private function getOption(): array
	{
		if (!$this->option) {
			$this->option = Options::get($this->optionName);
			if (!$this->option) {
				throw new Error("packages.backuping.backup.config_not_found");
			}
		}

		return $this->option;
	}

}
