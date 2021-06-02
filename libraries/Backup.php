<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{view\Error, IO, Log, Options};

class Backup {
	public const CONFIG_OPTION_NAME = "packages.backuping.config";

	protected static ?bool $configLoaded = false;
	protected static array $config = array(
		"sources" => null,
		"destinations" => null,
		"report" => array(
			"subject" => null,
			"sender" => null,
			"receivers" => null,
		),
	);

	public static function loadConfig(bool $useCache = true): ?array {
		$log = Log::getInstance();
		$log->info("Backup:loadConfig");

		if (!self::$configLoaded or $useCache) {

			$option = Options::get(self::CONFIG_OPTION_NAME);
			if (!$option) {
				$log->error("the config: '" . self::CONFIG_OPTION_NAME . "' is not found!");
				throw new Error("packages.backuping.backup.config_not_found");
			}

			$log->info("try prepare sources...");
			$sources = $option["sources"] ?? null;
			if ($sources) {
				if (!is_array($sources)) {
					$log->error("the given 'sources' is not array!");
					throw new InvalidArgumentException("the given sources should be array");
				}
				foreach ($sources as $sourceArray) {
					self::addSource(Source::fromArray($sourceArray));
				}
			} else {
				$log->reply()->error("sources is empty!");
				throw new Error("packages.backuping.Backup.sources_is_empty");
			}

			$log->info("try prepare destinations...");
			$destinations = $option["destinations"] ?? null;
			if ($destinations) {
				if (!is_array($destinations)) {
					$log->error("the given 'destinations' is not array!");
					throw new InvalidArgumentException("the given destinations should be array");
				}
				foreach ($destinations as $destinationArray) {
					self::addDestination(Destination::fromArray($destinationArray));
				}
			} else {
				$log->reply()->error("sources is empty!");
				throw new Error("packages.backuping.Backup.destinations_is_empty");
			}

			$log->info("try prepare report");
			$report = $option["report"] ?? null;
			if ($report) {
				$subject = $report["subject"] ?? "backup report";
				if (!is_string($subject)) {
					$log->error("report subject should be string");
					throw new InvalidArgumentException("report subject should be string");
				}
				self::$config["report"]["subject"] = $subject;

				$sender = $report["sender"] ?? null;
				if (!$sender or !is_array($sender)) {
					$log->error("you should pass email sender as array with 'email' (and 'name') index or remove report index to skip report");
					throw new InvalidArgumentException("you should pass email sender as array with 'email' (and 'name') index or remove report index to skip report");
				}
				self::$config["report"]["sender"] = $sender;

				$receivers = $report["receivers"] ?? [];
				if (!$receivers) {
					$log->error("you should add receivers to report is sendable");
					throw new InvalidArgumentException("you should add receivers to report is sendable");
				}
				self::$config["report"]["receivers"] = $receivers;

			} else {
				$log->warn("the report is empty, it seems no need to report");
				trigger_error("the report is empty, it seems no need to report");
			}

			self::$configLoaded = true;
		} else {
			$log->reply("config is loaded");
		}
		return self::$config;
	}

	public static function getSources(bool $useCache = true): array {
		if (empty(self::$config["sources"])) {
			self::loadConfig();
		}
		return self::$config["sources"];
	}

	public static function addSource(Source $src): void {
		if (self::$config["sources"] === null) {
			self::$config["sources"] = array();
		}
		$id = $src->getID();
		foreach (self::$config["sources"] as $source) {
			if ($source->getID() == $id) {
				throw new Error("packages.backuping.Backup.source_id_is_duplicate");
			}
		}
		self::$config["sources"][] = $src;
	}

	public static function getDestinations(bool $useCache = true): array {
		return self::$config["destinations"] ?? [];
	}

	public static function addDestination(Destination $dst): void {
		if (self::$config["destinations"] === null) {
			self::$config["destinations"] = array();
		}
		self::$config["destinations"][] = $dst;
	}

	public static function getReportInfo(): ?array {
		return self::$config["report"] ?? null;
	}

}
