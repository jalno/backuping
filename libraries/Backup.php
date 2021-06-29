<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{view\Error, IO, Log, Options};

class Backup {
	public const CONFIG_OPTION_NAME = "packages.backuping.config";

	protected string $optionName;
	protected bool $configLoaded = false;
	protected array $config = array(
		"sources" => array(),
		"destinations" => array(),
		"report" => array(
			"subject" => null,
			"sender" => null,
			"receivers" => null,
		),
	);

	public function __construct(string $optionName = self::CONFIG_OPTION_NAME) {
		$this->optionName = $optionName;
	}

	public function loadConfig(bool $reload = false): ?array {
		$log = Log::getInstance();
		$log->info("Backup:loadConfig");

		if (!$this->configLoaded or $reload) {

			$option = Options::get($this->optionName);
			if (!$option) {
				$log->error("the config: '{$this->optionName}' is not found!");
				throw new Error("packages.backuping.backup.config_not_found");
			}

			$log->info("try prepare sources...");
			$sources = $option["sources"] ?? null;
			if (empty($sources)) {
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

			$log->info("try prepare destinations...");
			$destinations = $option["destinations"] ?? null;
			if (empty($destinations)) {
				$log->reply()->error("sources is empty!");
				throw new Error("packages.backuping.Backup.destinations_is_empty");
			}
			if (!is_array($destinations)) {
				$log->error("the given 'destinations' is not array!");
				throw new InvalidArgumentException("the given destinations should be array");
			}
			foreach ($destinations as $destinationArray) {
				$this->addDestination(Destination::fromArray($destinationArray));
			}

			$log->info("try prepare report");
			$report = $option["report"] ?? null;
			if (!$report) {
				$log->warn("the report is empty, it seems no need to report");
				trigger_error("the report is empty, it seems no need to report");
			}
			if ($report and isset($report["sender"]) and isset($report["receivers"])) {
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
				$this->config["report"]["sender"] = $sender;

				$receivers = $report["receivers"] ?? [];
				if (!$receivers) {
					$log->error("you should add receivers to report is sendable");
					throw new InvalidArgumentException("you should add receivers to report is sendable");
				}
				$this->config["report"]["receivers"] = $receivers;
			}

			$this->configLoaded = true;
		} else {
			$log->reply("config is loaded");
		}
		return $this->config;
	}

	public function getSources(): array {
		return $this->config["sources"];
	}

	public function addSource(Source $src): void {
		$id = $src->getID();
		$sourcesIDs = array_map(fn(Source $s) => $s->getID(), $this->getSources());
		if (in_array($id, $sourcesIDs)) {
			throw new Error("packages.backuping.Backup.source_id_is_duplicate");
		}
		$this->config["sources"][] = $src;
	}

	public function getDestinations(): array {
		return $this->config["destinations"];
	}

	public function addDestination(Destination $dst): void {
		$id = $dst->getID();
		$destinationsIDs = array_map(fn(Destination $d) => $d->getID(), $this->getDestinations());
		if (in_array($id, $destinationsIDs)) {
			throw new Error("packages.backuping.Backup.destination_id_is_duplicate");
		}
		$this->config["destinations"][] = $dst;
	}

	public function getReportInfo(): ?array {
		return $this->config["report"] ?? null;
	}

}
