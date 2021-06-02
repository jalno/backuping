<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{view\Error, IO, Log};

class Destination {
	public static function fromArray(array $arr): self {
		$log = Log::getInstance();
		$log->info("Destination:fromArray");

		$directory = $arr["directory"] ?? null;
		if (empty($directory)) {
			$log->error("you should pass 'directory' index that is instance of '" . IO\Directory::class . "'");
			throw new InvalidArgumentException("you should pass 'directory' index that is instance of '" . IO\Directory::class . "'");
		}

		$directoryObj = null;
		$options = $arr["options"] ?? [];
		if (!is_array($options)) {
			$log->error("the given 'options' index is not array!");
			throw new InvalidArgumentException("the given 'options' index is not array!");
		}

		if (is_string($directory)) {
			if (strpos($directory, "/") !== false or $directory[0] == ".") {

				$directoryObj = new IO\Directory\Local($directory);

			} elseif (in_array($directory, ["local", "ftp", "sftp", "scp"])) {

				$path = $options["path"] ?? null;
				if (empty($path) or !is_string($path)) {
					$log->error("you should pass 'path' index in options for local, ftp, sftp and scp types!");
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

						$hostname = $options["hostname"] ?? null;
						if ($hostname) {
							if (!is_string($hostname)) {
								$log->error("the given 'hostname' is not valid, it should be string!, '" . gettype($hostname) . "' given");
								throw new InvalidArgumentException("the given 'hostname' is not valid, it should be string!, '" . gettype($hostname) . "' given");
							}
							$directoryObj->hostname = $hostname;
						} else {
							$log->error("you should pass 'hostname' index in option!");
							throw new InvalidArgumentException("you should pass 'hostname' index in option!");
						}

						$port = $options["port"] ?? null;
						if ($port) {
							if (!is_numeric($port) or $port <= 0 or $port > 65535) {
								$log->error("the given 'port' is not valid, it should be numeric and between 1 and 65535");
								throw new InvalidArgumentException("the given 'port' is not valid, it should be numeric and between 1 and 65535");
							}
							$directoryObj->port = $port;
						}

						$username = $options["username"] ?? null;
						if ($username) {
							if (!is_string($username)) {
								$log->error("the given username is not valid, it should be string, '" . gettype($username) . "' given!");
								throw new InvalidArgumentException("the given username is not valid, it should be string, '" . gettype($username) . "' given!");
							}
							$directoryObj->username = $username;
						} else {
							$log->error("you should pass a 'username' for this type!");
							throw new InvalidArgumentException("you should pass a 'username' for this type!");
						}

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
						break;

					case "scp":
					case "s3":
						$log->info("not implemented yet!");
						throw new Error("not_implemented");
				}
			}

		} elseif (is_callable($directory)) {
			$log->info("directory is callable, so call it...");
			$directoryObj = $directory($options);
		}
		if (!($directoryObj instanceof IO\Directory)) {
			$log->error("the given directory is not understood! type: '" . gettype($directory) . "'");
			throw new InvalidArgumentException("the given directory is not understood! type: '" . gettype($directory) . "'");
		}

		$lifetime = $arr["lifetime"] ?? null;

		return new self($directoryObj, $lifetime, $options);
	}

	protected ?IO\Directory $directory;

	protected ?int $lifetime;

	protected ?array $options;

	public function __construct(IO\Directory $directory, ?int $lifetime = null, ?array $options) {
		$this->directory = $directory;
		$this->lifetime = $lifetime;
		$this->options = $options;
	}

	public function getDirectory(): IO\Directory {
		return $this->directory;
	}

	public function getLifeTime(): ?int {
		return $this->lifetime;
	}

	public function getOptions(): array {
		return $this->options ?? [];
	}

	public function getOption(string $name) {
		return $this->options[$name] ?? null;
	}
	
}