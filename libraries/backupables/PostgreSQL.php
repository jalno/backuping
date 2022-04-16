<?php
namespace packages\backuping\backupables;

use packages\backuping\Exceptions\{InvalidArgumentException, RuntimeException};
use packages\base\{Date, Exception, IO, DB\MysqliDb};
use packages\backuping\{IBackupable, Log};

class PostgreSQL implements IBackupable {

	protected array $excludes = [];

	protected array $only = [];

	protected ?array $dbInfo = array(
		"host" => null,
		"port" => 5432,
		"username" => null,
		"password" => null,
	);

	public function backup(array $options = array()) {
		$log = Log::getInstance();
		$log->info("start postgresql backup");

		$this->validateDbInfo($options);

		$onlyOption = $options["only"] ?? [];
		if (!is_array($onlyOption)) {
			$log->error("the 'include' option should be array! (" . gettype($onlyOption) . ") given!");
			throw new InvalidArgumentException("the 'include' option should be array! (" . gettype($onlyOption) . ") given!");
		} elseif ($onlyOption) {
			$this->only = $this->getOnly($onlyOption);
		}

		$excludeOption = $options["exclude"] ?? [];
		if (!is_array($excludeOption)) {
			$log->error("the 'exclude' option should be array! (" . gettype($excludeOption) . ") given!");
			throw new InvalidArgumentException("the 'exclude' option should be array! (" . gettype($excludeOption) . ") given!");
		} elseif ($this->only and $excludeOption) {
			$log->error('you can only pass one of "only" or "exclude" at the same time, currently you passed "only" and "exclude" not acceptable');
			throw new InvalidArgumentException('you can only pass one of "only" or "exclude" at the same time, currently you passed "only" and "exclude" not acceptable');
		} elseif ($excludeOption) {
			$this->excludes = $this->getExcludes($excludeOption);
		}

		$jobs = $options['jobs'] ?? null;
		if (null !== $jobs and (!is_numeric($jobs) or $jobs <= 0)) {
			$log->error('the "jobs" should be numeric and bigger than zero');
			throw new InvalidArgumentException('the "jobs" should be numeric and bigger than zero');
		}

		$compressLevel = $options['compress'] ?? null;
		if (null !== $compressLevel and (!is_numeric($compressLevel) or $jobs < 0 or $compressLevel > 9)) {
			$log->error('the "compress" should be numeric and in range 0-9');
			throw new InvalidArgumentException('the "compress" should be numeric and in range 0-9');
		}

		$time = Date::time();

		$repo = new IO\Directory\TMP();

		if ($this->only) {

			// c|d|t|p
			$format = $options['only-format'] ?? null;
			if (null !== $format and !in_array($format, ['c', 'd', 't', 'p'])) {
				$log->error('the "only-format" null or one of (c | d | t | p)');
				throw new InvalidArgumentException('the "only-format" null or one of (c | d | t | p)');
			}

			$log->debug('check pg_dump is exists?');
			$commandExists = $this->ensureCommand('pg_dump');
			if (!$commandExists) {
				$log->reply()->error('not exist! we rely on this command to take backup!');
				throw new RuntimeException('the pg_dump command not exists!');
			}
			$log->reply('yes');

			
			$baseCommand = sprintf(
				'pg_dump --no-password --host=%s --username=%s --port=%s',
				escapeshellcmd($this->dbInfo["host"]),
				escapeshellcmd($this->dbInfo["username"]),
				escapeshellcmd($this->dbInfo["port"])
			);
			if ($this->dbInfo["password"]) {
				$baseCommand = sprintf('PGPASSWORD="%s"', escapeshellcmd($this->dbInfo["password"])) . ' ' . $baseCommand;
			}

			if ($jobs) {
				$baseCommand .= ' ' . sprintf('--jobs=%s', $jobs);
			}
			if (null !== $compressLevel) {
				$baseCommand .= ' ' . sprintf('--compress=%s', $compressLevel);
			}
			if ($format) {
				$baseCommand .= ' ' . sprintf('--format=%s', $format);
			}

			foreach ($this->only as $dbName => $tables) {
				$log->info("get backup of database: {$dbName} tables:", ($tables ? $tables : 'all tables'));

				$command = $baseCommand . ' ' . sprintf('--dbname=%s', escapeshellcmd($dbName));
				if ($tables) {
					$onlyTables = array_map(
						fn(string $table) => sprintf('--table=%s', escapeshellcmd($table)),
						$tables
					);
					$command .= ' ' . implode(' ', $onlyTables);
				}
				
				$node = $repo->file("{$dbName}-{$time}.pgsql");
				if ($format) {
					$format = strtolower(trim($format));
					if ($format == 'c') {
						$node = $repo->file("{$dbName}-{$time}.dump");
					} elseif ($format == 'd') {
						$node = $repo->directory("{$dbName}-{$time}");
					} elseif ($format == 't') {
						$node = $repo->file("{$dbName}-{$time}.tar");
					}
				}
				$file = $repo->file("{$dbName}-{$time}.pgsql");
				$command .= ' ' . sprintf('--file=%s', escapeshellcmd($node->getPath()));
				
				$command .= ' 2>&1';
				$log->info("run command:", $command);
				$output = null;
				$status = null;
				exec($command, $output, $status);
				$log->reply("output:", $output, "status code:", $status);

				if ($status != 0) {
					throw new Exception(implode("\n", $output));
				}
			}

			return $repo;
		}

		$baseCommand = sprintf(
			'pg_dumpall --no-password --host=%s --username=%s --port=%s',
			escapeshellcmd($this->dbInfo["host"]),
			escapeshellcmd($this->dbInfo["username"]),
			escapeshellcmd($this->dbInfo["port"])
		);
		if ($this->dbInfo["password"]) {
			$baseCommand = sprintf('PGPASSWORD="%s"', escapeshellcmd($this->dbInfo["password"])) . ' ' . $baseCommand;
		}

		$command = $baseCommand;
		if ($this->excludes) {
			$excludeDBs = array_map(
				fn(string $db) => sprintf('--exclude-database=%s', escapeshellcmd($db)),
				$this->excludes
			);
			$command .= ' ' . implode(' ', $excludeDBs);

		}
		$file = $repo->file("pg_dumpall-{$time}.pgsql");
		$command .= ' ' . sprintf('--file=%s', escapeshellcmd($file->getPath()));
		$command .= ' 2>&1';

		$log->info("run command:", $command);
		$output = null;
		$status = null;
		exec($command, $output, $status);
		$log->reply("output:", $output, "status code:", $status);

		if ($status != 0) {
			throw new Exception(implode("\n", $output));
		}

		return $repo;
	}

	/**
	 * @param \packages\base\IO\Directory $repo
	 */
	public function restore($repo, array $options = array()): void {
		$log = Log::getInstance();
		$log->info("start postgresql restore");

		$this->validateDbInfo($options);

		$jobs = $options['jobs'] ?? null;
		if (null !== $jobs and (!is_numeric($jobs) or $jobs <= 0)) {
			$log->error('the "jobs" should be numeric and bigger than zero');
			throw new InvalidArgumentException('the "jobs" should be numeric and bigger than zero');
		}

		$baseCommand = sprintf(
			'pg_restore --no-password --host=%s --username=%s --port=%s',
			escapeshellcmd($this->dbInfo["host"]),
			escapeshellcmd($this->dbInfo["username"]),
			escapeshellcmd($this->dbInfo["port"])
		);
		if ($this->dbInfo["password"]) {
			$baseCommand = sprintf('PGPASSWORD="%s"', escapeshellcmd($this->dbInfo["password"])) . ' ' . $baseCommand;
		}
		if ($jobs) {
			$baseCommand .= ' ' . sprintf('--jobs=%s', $jobs);
		}

		foreach ($repo->items(false) as $item) {
			$command = $baseCommand;

			$log->info("start restore {$item->basename}");
			$command .= ' ' . sprintf('--file=%s', escapeshellcmd($item->getPath()));
			$command .= ' 2>&1';

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

	protected function ensureCommand(string $command): bool {
		return boolval(shell_exec("command -v {$command}"));
	}

	private function validateDbInfo(array $options): void {
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
			$this->dbInfo["port"] = 5432;
		}
	}

	private function getExcludes(array $input): array {
		$log = Log::getInstance();

		foreach ($input as $key => $dbName) {
			if (!is_string($dbName)) {
				$log->error("the item: ({$key}) in array is not supported! only string is supported, (" . gettype($dbName) . ") given!");
				throw new InvalidArgumentException("the item: ({$key}) in array is not supported! only string is supported, (" . gettype($dbName) . ") given!");
			}
		}

		return $input;
	}

	private function getOnly(array $input): array {
		$log = Log::getInstance();
		$result = array();
		foreach ($input as $key => $dbNameOrTableArray) {
			if (is_array($dbNameOrTableArray)) {
				if (!isset($result[$key])) {
					$result[$key] = array();
				}
				foreach ($dbNameOrTableArray as $tkey => $tableName) {
					if (!is_string($tableName)) {
						$log->error("the item with index: ({$tkey}) in tables of database: ({$key}) array is not supported! only string is supported, (" . gettype($tableName) . ") given!", $tableName);
						throw new InvalidArgumentException("the item with index: ({$tkey}) in tables of database: ({$key}) array is not supported! only string is supported, (" . gettype($tableName) . ") given!");
					}
					$result[$key][] = $tableName;
				}
			} elseif (is_string($dbNameOrTableArray)) {
				if (!isset($result[$dbNameOrTableArray])) {
					$result[$dbNameOrTableArray] = array();
				}
			} else {
				$log->error("the item: ({$key}) in array is not supported! only string or array is supported, (" . gettype($dbNameOrTableArray) . ") given!");
				throw new InvalidArgumentException("the item: ({$key}) in array is not supported! only string or array is supported, (" . gettype($dbNameOrTableArray) . ") given!");
			}
		}
		return $result;
	}
}