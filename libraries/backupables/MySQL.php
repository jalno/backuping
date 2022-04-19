<?php
namespace packages\backuping\backupables;

use packages\backuping\Exceptions\{InvalidArgumentException, RuntimeException};
use packages\base\{Date, Exception, IO, DB\MysqliDb, IO\Node};
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

	public function backup(array $options = array()): Node {
		$log = Log::getInstance();
		$log->info("start mysql backup");

		$excludes = [];
		$excludeOption = $options["exclude"] ?? [];
		if (!is_array($excludeOption)) {
			$log->error("the 'exclude' option should be array! (" . gettype($excludeOption) . ") given!");
			throw new \InvalidArgumentException("the 'exclude' option should be array! (" . gettype($excludeOption) . ") given!");
		} elseif ($excludeOption) {
			$excludes = $this->getExcludes($excludeOption);
		}

		$includes = [];
		$includeOption = $options["only"] ?? [];
		if (!is_array($includeOption)) {
			$log->error("the 'include' option should be array! (" . gettype($includeOption) . ") given!");
			throw new \InvalidArgumentException("the 'include' option should be array! (" . gettype($includeOption) . ") given!");
		} elseif ($includeOption) {
			$includes = $this->getIncludes($includeOption);
		}

		$seprate = isset($options["seprate"]) and $options["seprate"];

		$shouldUseGzip = isset($options["gzip"]) and $options["gzip"];

		$useGzip = false;
		if ($shouldUseGzip) {
			$log->info("check has gzip?");
			$useGzip = $this->ensureCommand("gzip");
			$log->reply("done:", $useGzip);
		}

		$connection = $this->getMysqliDB($options);

		$time = Date::time();

		$baseCommand = "mysqldump";
		$baseCommand .= " --host=" . escapeshellcmd($this->dbInfo["host"]);
		$baseCommand .= " --port=" . escapeshellcmd($this->dbInfo["port"]);
		$baseCommand .= " --user=" . escapeshellcmd($this->dbInfo["username"]);
		$baseCommand .= " --password=" . escapeshellcmd($this->dbInfo["password"]);

		$repo = new IO\Directory\TMP();

		if ($includes) {
			foreach ($includes as $dbName => $tables) {
				$log->info("get backup of database: {$dbName}");
				$file = $repo->file("{$dbName}-{$time}.sql" . ($useGzip ? ".gz" : ""));

				$implodeTables = implode(" ", $tables);
				$command = $baseCommand;
				$command .= " {$dbName}". ($implodeTables ? " {$implodeTables}" : "");
				$command .= ($useGzip ? " | gzip -c" : "") . " > " . $file->getPath();
				$command .= " 2>&1";

				$log->info("run command:", $command);
				$output = null;
				$status = null;
				exec($command, $output, $status);
				$log->reply("output:", $output, "status code:", $status);

				if ($status != 0) {
					throw new Exception(implode("\n", $output));
				}

				if ($file->exists()) {
					$log->info("done, file size:", $file->size());
				} else {
					$log->error("can not get backup!");
					throw new Exception("packages.backuping.backupable.can_not_get_backup");
				}
			}
		} elseif ($seprate) {
			$log->info("get databases...");
			$databases = $this->databases();
			$log->reply("done, count:" . count($databases), $databases);

			if ($excludes) {
				$log->info("exclude:", $excludes);
			}

			foreach ($databases as $database) {
				$log->info("get backup of database: {$database}");
				$file = $repo->file("{$database}-{$time}.sql" . ($useGzip ? ".gz" : ""));

				if (isset($excludes[$database]) and empty($excludes[$database])) {
					$log->warn("skip database: '{$database}' ...");
					continue;
				}

				$ignoredTables = array_map(
					fn(string $table) => "--ignore-table={$database}." . $table,
					$excludes[$database] ?? []
				);
				$implodeTables = implode(" ", $ignoredTables);

				$command = $baseCommand;
				$command .= " {$database}" . ($implodeTables ? " {$implodeTables}" : "");
				$command .= ($useGzip ? " | gzip -c" : "") . " > " . $file->getPath();
				$command .= " 2>&1";

				$log->info("run command:", $command);

				$output = null;
				$status = null;
				exec($command, $output, $status);
				$log->reply("output:", $output, "status code:", $status);

				if ($status != 0) {
					throw new Exception(implode("\n", $output));
				}

				if ($file->exists()) {
					$log->info("done, file size:", $file->size());
				} else {
					$log->error("can not get backup!");
					throw new Exception("packages.backuping.backupable.can_not_get_backup");
				}
			}
		} else {
			$file = $repo->file("-{$time}.sql" . ($useGzip ? ".gz" : ""));

			$ignoreTables = [];
			$ignoredDatabases = [];
			foreach ($excludes as $dbName => $tablesArray) {
				if (empty($tablesArray)) {
					$ignoredDatabases[] = "--ignore-database={$dbName}";
				} else {
					foreach ($tablesArray as $table) {
						$ignoreTables[] = "--ignore-table={$dbName}.{$table}";
					}
				}
			}

			$command = $baseCommand;
			$command .= " --all-databases";
			$command .= $ignoredDatabases ? " " . implode(" ", $ignoredDatabases) : "";
			$command .= $ignoreTables ? " " . implode(" ", $ignoreTables) : "";
			$command .= ($useGzip ? " | gzip -c" : "") . " > " . $file->getPath();
			$command .= " 2>&1";

			$log->info("run command:", $command);

			$output = null;
			$status = null;
			exec($command, $output, $status);
			$log->reply("output:", $output, "status code:", $status);

			if ($status != 0) {
				throw new Exception(implode("\n", $output));
			}

			if ($file->exists()) {
				$log->reply("done, file size:", $file->size());
			} else {
				$log->reply()->error("can not get backup!");
				throw new Exception("packages.backuping.backupable.can_not_get_backup");
			}
		}
		return $repo;
	}

	public function restore(Node $repo, array $options = array()): void {
		$this->getMysqliDB($options);
		$log = Log::getInstance();
		$log->info("start mysql restore");

		if (!($repo instanceof IO\Directory)) {
			$log->error("the given repo must be a directory for this backupable! (" . __CLASS__ . ")");
			throw new RuntimeException("the given repo must be a directory for this backupable! (" . __CLASS__ . ")");
		}

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
					$command .= " 2>&1";

					$log->info("run command:", $command);
					$output = null;
					$status = null;
					exec($command, $output, $status);
					$log->reply("output:", $output, "status code:", $status);
					if ($status != 0) {
						throw new Exception(implode("\n", $output));
					}
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
				$command .= " " . ($dbName ? $dbName : "") . " < " . $file->getPath();
				$command .= " 2>&1";

				$log->info("run command:", $command);
				$output = null;
				$status = null;
				exec($command, $output, $status);
				$log->reply("output:", $output, "status code:", $status);
				if ($status != 0) {
					throw new Exception(implode("\n", $output));
				}
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
				$this->dbInfo["port"] = 3306;
			}

			$this->connection = new MysqliDb($this->dbInfo["host"], $this->dbInfo["username"], $this->dbInfo["password"], null, $this->dbInfo["port"]);
			$this->connection->ping();
		}
		return $this->connection;
	}
	protected function ensureCommand(string $command): bool {
		return boolval(shell_exec("command -v {$command}"));
	}
	protected function getIncludes(array $input): array {
		return $this->validateIncludeExclude($input, "include");
	}
	protected function getExcludes(array $input): array {
		return $this->validateIncludeExclude($input, "exclude");
	}
	private function validateIncludeExclude(array $input, string $type = ""): array {
		$log = Log::getInstance();
		$result = array();
		foreach ($input as $key => $dbNameOrTableArray) {
			if (is_array($dbNameOrTableArray)) {
				if (!isset($result[$key])) {
					$result[$key] = array();
				}
				foreach ($dbNameOrTableArray as $tkey => $tableName) {
					if (!is_string($tableName)) {
						$log->error("the item with index: ({$tkey}) in tables of database: ({$key}) '{$type}' array is not supported! only string is supported, (" . gettype($tableName) . ") given!", $tableName);
						throw new \InvalidArgumentException("the item with index: ({$tkey}) in tables of database: ({$key}) '{$type}' array is not supported! only string is supported, (" . gettype($tableName) . ") given!");
					}
					$result[$key][] = $tableName;
				}
			} elseif (is_string($dbNameOrTableArray)) {
				if (!isset($result[$dbNameOrTableArray])) {
					$result[$dbNameOrTableArray] = array();
				}
			} else {
				$log->error("the item: ({$key}) in '{$type}' array is not supported! only string or array is supported, (" . gettype($dbNameOrTableArray) . ") given!");
				throw new \InvalidArgumentException("the item: ({$key}) in '{$type}' array is not supported! only string or array is supported, (" . gettype($dbNameOrTableArray) . ") given!");
			}
		}
		return $result;
	}
}