<?php
namespace packages\backuping\backupables;

use InvalidArgumentException;
use packages\base\{Date, Exception, IO, DB\MysqliDb, IO\Node};
use packages\backuping\{IBackupable, Log};

class MongoDB implements IBackupable {

	protected const MONGO_URI_REGEX = '/^mongodb:\/\/(?:(?:[^:]+):(?:[^@]+)?@)?(?:(?:(?:[^\/]+)|(?:\/.+.sock?),?)+)(?:\/([^\/\.\ "*<>:\|\?]*))?(?:\?(?:(.+=.+)&?)+)*$/';

	protected ?string $mongoURI = null;
	protected array $dbInfo = array(
		"host" => null,
		"port" => 27017,
		"username" => null,
		"password" => null,
	);

	public function backup(array $options = array()): Node {
		$log = Log::getInstance();
		$log->info("start mongoDB backup");

		$this->validateDatabaseInfo($options);

		$databases = $this->getDatabases($options);
		$excludeCollections = $this->getExcludeCollection($options);
		$excludeCollectionsWithPrefix = $this->getExcludeCollectionsWithPrefix($options);

		if (($excludeCollections or $excludeCollectionsWithPrefix) and empty($databases)) {
			throw new InvalidArgumentException(
				"bad option: 'db' is required when 'excludeCollection' or 'excludeCollectionsWithPrefix' is specified"
			);
		}

		if (!$this->commandExists("mongodump")) {
			throw new Exception("command 'mongodump' not exists!");
		}

		$command = "mongodump";

		if ($this->mongoURI) {
			$command .= " --uri=" . $this->mongoURI;
		} else {
			$command .= " --host=" . escapeshellcmd($this->dbInfo["host"]);
			$command .= " --port=" . escapeshellcmd($this->dbInfo["port"]);
			$command .= " --username=" . escapeshellcmd($this->dbInfo["username"]);
			$command .= " --password=" . escapeshellcmd($this->dbInfo["password"]);
		}

		if ($databases) {
			foreach ($databases as $database => $collections) {
				$command .= " --db=" . $database;
				if ($collections) {
					$command .= implode(" --collection=", $collections);
				}
			}
		}

		if ($excludeCollections) {
			$command .= " --excludeCollection=" . implode(" --excludeCollection=", $excludeCollections);
		}

		if ($excludeCollectionsWithPrefix) {
			$command .= " --excludeCollectionsWithPrefix=" . implode(" --excludeCollectionsWithPrefix=", $excludeCollectionsWithPrefix);
		}

		if ($options["gzip"] ?? false) {
			$command .= " --gzip";
		}

		$repo = new IO\Directory\TMP();
		$command .= " --out=" . $repo->getRealPath();

		$command .= " 2>&1";

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
	 * @param array $options
	 */
	public function restore(Node $repo, array $options = array()): void {
		$this->validateDatabaseInfo($options);

		$log = Log::getInstance();
		$log->info("start mongoDB restore");

		$databases = $this->getDatabases($options);

		$command = "mongorestore";
		if ($this->mongoURI) {
			$command .= " --uri=" . rawurlencode($this->mongoURI);
		} else {
			$command .= " --host=" . escapeshellcmd($this->dbInfo["host"]);
			$command .= " --port=" . escapeshellcmd($this->dbInfo["port"]);
			$command .= " --username=" . escapeshellcmd($this->dbInfo["username"]);
			$command .= " --password=" . escapeshellcmd($this->dbInfo["password"]);
		}

		if ($options["gzip"] ?? false) {
			$command .= " --gzip";
		}

		if (method_exists($repo, 'getRealPath')) {
			$command .= " --dir=" . $repo->getRealPath();
		} else {
			$command .= " --dir=" . $repo->getPath();
		}

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

	protected function commandExists(string $command): bool {
		return boolval(shell_exec("command -v {$command}"));
	}

	protected function validateDatabaseInfo(array $options): void {

		$mongoURI = $options['uri'] ?? null;
		if ($mongoURI) {
			if (!is_string($mongoURI)) {
				throw new \InvalidArgumentException(
					"the 'uri' options should be string! (" . gettype($mongoURI) . ") given!"
				);
			}
			if (!preg_match(self::MONGO_URI_REGEX, $mongoURI)) {
				throw new \InvalidArgumentException(
					"the 'uri' is not in valid formats!" .
					" It should be in: mongodb://[username:password@]host1[:port1][,...hostN[:portN]][/[defaultauthdb][?options]] format" .
					" the given value: " . $mongoURI
				);
			}
			$this->mongoURI = $mongoURI;
		} else {
			
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
				$this->dbInfo["port"] = 27017;
			}
		}

	}

	protected function getDatabases(array $options): array {
		$log = Log::getInstance();
		$databases = $options["db"] ?? [];
		if ($databases) {
			if (!is_array($databases)) {
				$log->error("the 'db' option should be array! (" . gettype($databases) . ") given!");
				throw new \InvalidArgumentException("the 'db' option should be array! (" . gettype($databases) . ") given!");
			}
			$databases = $this->validateDatabases($databases, "db");
		}
		return $databases;
	}

	protected function getExcludeCollection(array $options): array {
		return $this->validateGeneralExclude($options, "excludeCollection");
	}

	protected function getExcludeCollectionsWithPrefix(array $options): array {
		return $this->validateGeneralExclude($options, "excludeCollectionsWithPrefix");
	}

	private function validateGeneralExclude(array $options, string $key): array {
		$excludes = $options[$key] ?? [];
		if ($excludes) {
			if (is_string($excludes)) {
				$excludes = [$excludes];
			}
			if (!is_array($excludes)) {
				throw new InvalidArgumentException(
					"the '{$key}' option should be array or string! (" . gettype($excludes) . ") given!"
				);
			}
			foreach ($excludes as $key => $value) {
				if (!is_string($value)) {
					throw new InvalidArgumentException(
						"the key {$key} in '{$key}' option should be string! (" . gettype($excludes) . ") given!"
					);
				}
			}
		}
		return $excludes;
	}

	private function validateDatabases(array $input, string $type = ""): array {
		$log = Log::getInstance();
		$result = array();
		foreach ($input as $key => $dbNameOrCollectionArray) {

			if (is_array($dbNameOrCollectionArray)) {
				$result[$key] = $result[$key] ?? [];

				foreach ($dbNameOrCollectionArray as $tkey => $collection) {
					if (!is_string($collection)) {
						$log->error("the item with index: ({$tkey}) in collections of database: ({$key}) '{$type}' array is not supported! only string is supported, (" . gettype($collection) . ") given!", $collection);
						throw new InvalidArgumentException("the item with index: ({$tkey}) in collections of database: ({$key}) '{$type}' array is not supported! only string is supported, (" . gettype($collection) . ") given!");
					}
					$result[$key][] = $collection;
				}

			} elseif (is_string($dbNameOrCollectionArray)) {
				$result[$dbNameOrCollectionArray] = $result[$dbNameOrCollectionArray] ?? [];
			} else {
				$log->error("the item: ({$key}) in '{$type}' array is not supported! only string or array is supported, (" . gettype($dbNameOrCollectionArray) . ") given!");
				throw new InvalidArgumentException("the item: ({$key}) in '{$type}' array is not supported! only string or array is supported, (" . gettype($dbNameOrCollectionArray) . ") given!");
			}

		}
		return $result;
	}
}
