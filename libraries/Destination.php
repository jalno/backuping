<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{view\Error, IO, Log};

class Destination {

	public static function fromArray(array $config): self {
		$log = Log::getInstance();
		$log->info("Destination:fromArray");

		$id = $config["id"] ?? null;
		if (empty($id)) {
			$log->error("you should pass an unique 'id' (string) index");
			throw new InvalidArgumentException("you should pass an unique 'id' (string) index");
		}
		if (!is_string($id)) {
			$log->error("the given 'id' index is not string!");
			throw new InvalidArgumentException("the given 'id' index is not string!");
		}

		$directory = $config["directory"] ?? null;
		if (empty($directory)) {
			$log->error("you should pass 'directory' index that is instance of '" . IO\Directory::class . "'");
			throw new InvalidArgumentException("you should pass 'directory' index that is instance of '" . IO\Directory::class . "'");
		}

		$directoryObj = null;
		$options = $config["options"] ?? [];
		if (!is_array($options)) {
			$log->error("the given 'options' index is not array!");
			throw new InvalidArgumentException("the given 'options' index is not array!");
		}

		if (is_string($directory)) {
			// start's with '.' or contains '/'
			if (strpos($directory, "/") !== false or $directory[0] == ".") {

				$directoryObj = new IO\Directory\Local($directory);

			} elseif (in_array($directory, ["local", "ftp", "sftp"])) {

				$path = $options["path"] ?? null;
				if (empty($path) or !is_string($path)) {
					$log->error("you should pass 'path' index in options for local, ftp and sftp types!");
					throw new InvalidArgumentException("you should pass 'path' index in options for local, ftp, sftp and scp types!");
				}
				switch ($directory) {
					case "local":
						$directoryObj = new IO\Directory\Local($path);
						break;
					case "ftp":
						$directoryObj = new IO\Directory\FTP($path);
					case "sftp":
						$directoryObj = new IO\Directory\SFTP($path);
						break;
				}
				if ($directory == "ftp" or $directory == "sftp") {
					$hostname = $options["hostname"] ?? null;
					if (empty($hostname)) {
						$log->error("you should pass 'hostname' index in option!");
						throw new InvalidArgumentException("you should pass 'hostname' index in option!");
					}
					if (!is_string($hostname)) {
						$log->error("the given 'hostname' is not valid, it should be string!, '" . gettype($hostname) . "' given");
						throw new InvalidArgumentException("the given 'hostname' is not valid, it should be string!, '" . gettype($hostname) . "' given");
					}
					$directoryObj->hostname = $hostname;

					$port = $options["port"] ?? null;
					if (empty($port)) {
						$log->error("you should pass 'port' index in option!");
						throw new InvalidArgumentException("you should pass 'port' index in option!");
					}
					if (!is_numeric($port) or $port <= 0 or $port > 65535) {
						$log->error("the given 'port' is not valid, it should be numeric and between 1 and 65535");
						throw new InvalidArgumentException("the given 'port' is not valid, it should be numeric and between 1 and 65535");
					}
					$directoryObj->port = $port;

					$username = $options["username"] ?? null;
					if (empty($username)) {
						$log->error("you should pass a 'username' for this type!");
						throw new InvalidArgumentException("you should pass a 'username' for this type!");
					}
					if (!is_string($username)) {
						$log->error("the given username is not valid, it should be string, '" . gettype($username) . "' given!");
						throw new InvalidArgumentException("the given username is not valid, it should be string, '" . gettype($username) . "' given!");
					}
					$directoryObj->username = $username;

					$password = $options["password"] ?? null;
					if ($password) {
						if (!is_string($password)) {
							$log->error("the given password is not valid, it should be string, '" . gettype($username) . "' given!");
							throw new InvalidArgumentException("the given password is not valid, it should be string, '" . gettype($username) . "' given!");
						}
					} else {
						$log->warn("the password is empty! this may be wrong! or maybe it's work correctly");
						trigger_error("the password is empty! this may be wrong! or maybe it's work correctly");
					}
				}
			}

		} elseif (is_callable($directory)) {
			$log->info("directory is callable, so call it...");
			$directoryObj = $directory($options);
			$log->reply("done", $directoryObj);
		}
		if (!($directoryObj instanceof IO\Directory)) {
			$log->error("the given directory is not understood! type: '" . gettype($directory) . "'");
			throw new InvalidArgumentException("the given directory is not understood! type: '" . gettype($directory) . "'");
		}

		$lifetime = $config["lifetime"] ?? null;

		if ($lifetime and !is_numeric($lifetime)) {
			$log->error("the 'lifetime' should be a valid number");
			throw new InvalidArgumentException("the 'lifetime' should be a valid number");
		}

		$log->info("destination object created! id:", $id);
		return new self($id, $directoryObj, $lifetime, $options);
	}

	protected string $id;

	protected IO\Directory $directory;

	protected ?int $lifetime;

	protected array $options;

	public function __construct(string $id, IO\Directory $directory, ?int $lifetime = null, array $options = array()) {
		$this->directory = $directory;
		$this->lifetime = $lifetime;
		$this->options = $options;
		$this->id = $id;
	}

	public function getID(): string {
		return $this->id;
	}

	public function getDirectory(): IO\Directory {
		return $this->directory;
	}

	public function getLifeTime(): ?int {
		return $this->lifetime;
	}

	public function getOptions(): array {
		return $this->options;
	}

	public function getOption(string $name) {
		return $this->options[$name] ?? null;
	}

}