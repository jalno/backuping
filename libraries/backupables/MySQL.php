<?php
namespace packages\backuping\backupables;

use \InvalidArgumentException;
use packages\base\{Date, view\Error, IO, DB\MysqliDb};
use packages\backuping\{IBackupable, Log};

class MySQL implements IBackupable {

	protected ?MysqliDb $connection = null;
	protected ?array $dbInfo = array(
		"host" => null,
		"port" => 3306,
		"username" => null,
		"password" => null,
	);
	protected ?array $tables = null;

	public function backup(array $options = array()) {
		$log = Log::getInstance();
		$log->info("start mysql backup");

		$excludes = $options["exclude"] ?? [];
		if (!is_array($excludes)) {
			$log->error("the 'exclude' option should be array! (" . gettype($excludes) . ") given!");
			throw new \InvalidArgumentException("the 'exclude' option should be array! (" . gettype($excludes) . ") given!");
		}

		$excludeDatabases = array();
		foreach ($excludes as $key => $databaseNameOrTables) {
			if (is_array($databaseNameOrTables)) {
				foreach ($databaseNameOrTables as $tkey => $tableName) {
					if (!is_string($tableName)) {
						$log->error("the item: ({$tkey}) in tables of database: ({$key}) 'exclude' array is not supported! only string or array is supported, (" . gettype($tableName) . ") given!", $tableName);
						throw new \InvalidArgumentException("the item: ({$tkey}) in tables of database: ({$key}) 'exclude' array is not supported! only string or array is supported, (" . gettype($tableName) . ") given!");
					}
				}
			} elseif (is_string($databaseNameOrTables)) {
				$excludeDatabases[] = $databaseNameOrTables;
			} else {
				$log->error("the item: ({$key}) in 'exclude' array is not supported! only string or array is supported, (" . gettype($databaseNameOrTables) . ") given!");
				throw new \InvalidArgumentException("the item: ({$key}) in 'exclude' array is not supported! only string or array is supported, (" . gettype($databaseNameOrTables) . ") given!");
			}
		}

		$connection = $this->getMysqliDB($options);

		$log->info("check has gzip?");
		$hasGzip = $this->ensureCommand("gzip");
		$log->reply("done:", $hasGzip);

		$time = Date::time();

		$baseCommand = "mysqldump";
		$baseCommand .= " --host=" . escapeshellcmd($this->dbInfo["host"]);
		$baseCommand .= " --port=" . escapeshellcmd($this->dbInfo["port"]);
		$baseCommand .= " --user=" . escapeshellcmd($this->dbInfo["username"]);
		$baseCommand .= " --password=" . escapeshellcmd($this->dbInfo["password"]);

		$seprate = $options["seprate"] ?? true;

		$backup = null;

		$repo = new IO\Directory\TMP();
		if ($seprate) {
			$log->info("get databases...");
			$databases = $this->databases();
			$log->reply("done, count:" . count($databases), $databases);
			
			if ($excludeDatabases) {
				$log->info("exclude:", $excludeDatabases);
				$databases = array_diff($databases, $excludeDatabases);
				$log->reply("done, count: " . count($databases));
			}

			
			foreach ($databases as $database) {
				$log->info("get backup of database: {$database}");
				$file = $repo->file("{$database}-{$time}.sql.gz");

				$ignoredTables = array_map(
					fn(string $table) => "--ignore-table={$database}." . $table,
					$excludes[$database] ?? []
				);

				$command = $baseCommand;
				$command .= " {$database} " . ($ignoredTables ? implode(" ", $ignoredTables) : "");
				$command .= ($hasGzip ? " | gzip -c" : "") . " > " . $file->getPath();

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
				break;
			}
		} else {
			$file = $repo->file("-{$time}.sql.gz");

			$ignoredDatabases = array_map(
				fn($dbName) => "--ignore-database=" . $dbName,
				$excludeDatabases
			);

			$command = $baseCommand;
			$command .= " --all-databases ";
			$command .= ($ignoredDatabases ? implode(" ", $ignoredDatabases) : "");
			$command .= ($hasGzip ? " | gzip -c" : "") . " > " . $file->getPath();

			$log->info("run command:", $command);

			$output = null;
			$status = null;
			exec($command, $output, $status);
			$log->reply("output:", $output, "status code:", $status);

			if ($file->exists()) {
				$log->reply("done, file size:", $file->size());
			} else {
				$log->reply()->error("can not get backup!");
				throw new Error("packages.backuping.backupable.can_not_get_backup");
			}
		}
		return $repo;
	}

	public function restore($backup, array $options = array()): void {
		$this->getMysqliDB($options);
		$log = Log::getInstance();
		$log->info("start mysql restore");

		$log->info("check has gzip?");
		$hasGzip = $this->ensureCommand("gzip");
		$log->reply("done:", $hasGzip);

		if ($hasGzip) {
			$log->info("try extract gz files");
			foreach ($repo->files(false) as $file) {
				$log->info("file:", $file->getPath());
				$ext = $file->getExtension();
				if ($ext == "gz") {
					$log->reply("is gz file! extract it");
					$command = "gzip -d " . $file->getPath();
					$log->info("run command:", $command);
					$output = null;
					$status = null;
					exec($command, $output, $status);
					$log->reply("output:", $output, "status code:", $status);
				}
			}
		}

		$log->info("try import each sql file...");
		foreach ($repo->files(false) as $file) {
			$log->info("file:", $file->getPath());
			$ext = $file->getExtension();
			if ($ext == "sql") {
				$log->reply("is sql file, try import");

				$lastdash = strrpos($file->basename, "-");
				if ($lastdash === false) {
					$log->error("file is not valid! can not find '-' char");
					continue;
				}
				$dbName = substr($file->basename, 0, $lastdash);

				$command = "mysql";
				$command .= " --host=" . escapeshellcmd($this->dbInfo["host"]);
				$command .= " --port=" . escapeshellcmd($this->dbInfo["port"]);
				$command .= " --user=" . escapeshellcmd($this->dbInfo["username"]);
				$command .= " --password=" . escapeshellcmd($this->dbInfo["password"]);
				$command .= ($dbName ? $dbName : "") . " < " . $file->getPath();

				$log->info("run command:", $command);
				$output = null;
				$status = null;
				exec($command, $output, $status);
				$log->reply("output:", $output, "status code:", $status);
			}
		}
	}

	protected function databases(): array {
		if ($this->tables === null) {
			$this->tables = array();
			$result = $this->connection->rawQuery("SHOW databases");
			$this->tables = array_column($result, "Database");
		}
		return $this->tables;
	}
	protected function getMysqliDB(array $options): MysqliDb {
		if ($this->connection === null) {

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

			$this->connection = new MysqliDb($this->dbInfo["host"], $this->dbInfo["username"], $this->dbInfo["password"], null, $this->dbInfo["port"]);
		}
		return $this->connection;
	}
	protected function ensureCommand(string $command): bool {
		return boolval(shell_exec("command -v {$command}"));
	}
}