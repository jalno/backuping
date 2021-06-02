<?php
namespace packages\backuping\backupable;

use \InvalidArgumentException;
use packages\base\{Date, view\Error, IO\Directory, DB\MysqliDb};
use packages\backuping\{IBackupable, Log};

class MySQL implements IBackupable {

	protected ?MysqliDb $db = null;
	protected ?array $dbInfo = array(
		"host" => null,
		"port" => 3306,
		"username" => null,
		"password" => null,
	);
	protected ?array $tables = null;

	public function backup(?Directory $repo, ?array $options = null) {
		$log = Log::getInstance();
		$log->info("start mysql backup");
		$db = $this->getMysqliDB($options);

		$prefix = $options["prefix"] ?? null;
		$seprate = $options["seprate"] ?? true;

		if ($seprate) {
			$log->info("get databases...");
			$databases = $this->databases();
			$log->reply($databases);

			foreach ($databases as $database) {
				$log->info("get backup of database: {$database}");
				$time = Date::time();

				$file = $repo->file(($prefix ? $prefix . "---" : "") . "{$database}---{$time}.sql.gz");

				$command = "mysqldump";
				$command .= " --host=" . $this->dbInfo["host"];
				$command .= " --port=" . $this->dbInfo["port"];
				$command .= " --user=" . $this->dbInfo["username"];
				$command .= " --password=" . $this->dbInfo["password"];
				$command .= " {$database} | gzip -c > " . $file->getPath();

				$log->info("run command:", $command);
				$output = null;
				$status = null;
				exec($command, $output, $status);
				$log->reply("output:", $output, "status code:", $status);

				if ($file->exists()) {
					$log->info("done, file size:", $file->size());
				} else {
					$log->error("can not get backup!");
					throw new Error("packages.backuping.backupable.can_not_get_backup");
				}
			}
		} else {
			$file = $repo->file(($prefix ? $prefix . "---" : "") . "{$time}.sql.gz");

			$command = "mysqldump";
			$command .= " --host=" . $this->dbInfo["host"];
			$command .= " --port=" . $this->dbInfo["port"];
			$command .= " --user=" . $this->dbInfo["username"];
			$command .= " --password=" . $this->dbInfo["password"];
			$command .= " --all-databases | gzip -c > " . $file->getPath();

			if ($file->exists()) {
				$log->reply("done, file size:", $file->size());
			} else {
				$log->reply()->error("can not get backup!");
				throw new Error("packages.backuping.backupable.can_not_get_backup");
			}
		}

	}

	public function restore(Directory $repo, ?array $options = null) {
		$db = $this->getMysqliDB($options);

	}
	protected function databases(bool $useCache = true): array {
		if ($this->tables === null or !$useCache) {
			$this->tables = array();
			$result = $this->db->rawQuery("SHOW databases");
			$this->tables = array_column($result, "Database");
		}
		return $this->tables;
	}
	protected function getMysqliDB(array $options): MysqliDb {
		if ($this->db === null) {

			$this->dbInfo["host"] = $options["host"] ?? "localhost";
			if ($this->dbInfo["host"]) {
				if (!is_string($this->dbInfo["host"])) {
					throw new InvalidArgumentException("the given 'host' should be string, '" . gettype($this->dbInfo["host"]) . "' given");
				}
			} else {
				throw new InvalidArgumentException("you should pass 'host' index in options!");
			}

			$this->dbInfo["username"] = $options["username"] ?? null;
			if ($this->dbInfo["username"]) {
				if (!is_string($this->dbInfo["username"])) {
					throw new InvalidArgumentException("the given 'username' should be string, '" . gettype($this->dbInfo["username"]) . "' given");
				}
			} else {
				throw new InvalidArgumentException("you should pass 'username' index in options!");
			}

			$this->dbInfo["password"] = $options["password"] ?? null;
			if ($this->dbInfo["password"]) {
				if (!is_string($this->dbInfo["password"])) {
					throw new InvalidArgumentException("the given 'password' should be string, '" . gettype($this->dbInfo["password"]) . "' given");
				}
			} else {
				trigger_error("the 'password' is empty! It may be okay, maybe not!");
			}

			$this->dbInfo["port"] = $options["port"] ?? null;
			if ($this->dbInfo["port"]) {
				if (!is_numeric($this->dbInfo["port"]) or $this->dbInfo["port"] <= 0 or $this->dbInfo["port"] > 65535) {
					throw new InvalidArgumentException("the given 'port' is not valid, it should be numeric and between 1 and 65535");
				}
			} else {
				trigger_error("the port is not given! so we use 3306 as default port!");
				$this->dbInfo["port"] = 3306;
			}

			$this->db = new MysqliDb($this->dbInfo["host"], $this->dbInfo["username"], $this->dbInfo["password"], null, $this->dbInfo["port"]);
		}
		return $this->db;
	}
}