<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{Log};

class Source {

	public static function fromArray(array $config): self {
		$log = Log::getInstance();
		$log->info("Source::fromArray");

		$id = $config["id"] ?? null;
		if (!$id) {
			$log->error("you should pass an unique 'id' (string) index");
			throw new InvalidArgumentException("you should pass an unique 'id' (string) index");
		}
		if (!is_string($id)) {
			$log->error("the given 'id' index is not string!");
			throw new InvalidArgumentException("the given 'id' index is not string!");
		}

		$options = $config["options"] ?? [];
		if (!is_array($options)) {
			$log->error("the given 'options' index is not array!");
			throw new InvalidArgumentException("the given 'options' index is not array!");
		}

		$type = $config["type"] ?? null;
		if (!$type) {
			$log->error("you should pass 'type' index that is instance or class-string of'" . IBackupable::class . "' or callable that return this type");
			throw new InvalidArgumentException("you should pass 'type' index that is instance or class-string of '" . IBackupable::class . "' or callable that return this type");
		}
		$backupable = null;
		if (is_string($type) and class_exists($type)) {
			$log->info("the type is class-string, so we try to create instance of it");
			$backupable = new $type();
		} elseif (is_callable($type)) {
			$log->info("type is callable, so call it");
			$backupable = $type($options);
			$log->reply("done", $backupable);
		} else {
			$backupable = $type;
		}
		if (!($backupable instanceof IBackupable)) {
			$log->error("the 'type' index should be instance or class-string of '" . IBackupable::class . "' or callable that return this type, (" . gettype($type) . ") given!");
			throw new InvalidArgumentException("the 'type' index should be instance or class-string of '" . IBackupable::class . "' or callable that return this type, (" . gettype($type) . ") given!");
		}

		$minBackups = $config["minimum_keeping_backups"] ?? null;
		if ($minBackups !== null) {
			if (!is_numeric($minBackups)) {
				$log->error("the given 'minimum_keeping_backups' is not numeric!");
				throw new InvalidArgumentException("the given 'minimum_keeping_backups' is not numeric!");
			} elseif ($minBackups < 0) {
				$log->error("the given 'minimum_keeping_backups' should be zero or bigger! given value: {$minBackups}");
				throw new InvalidArgumentException("the given 'minimum_keeping_backups' should be zero or bigger! given value: {$minBackups}");
			}
		}

		$cleanupOnBackup = $config["cleanup_on_backup"] ?? null;

		$log->info("create new source with id: '{$id}'");
		$source = new self($id, $backupable, $options);
		if ($minBackups !== null) {
			$source->setMinKeepingBackupCount($minBackups);
		}
		if ($cleanupOnBackup !== null) {
			$source->setCleanupOnBackup(boolval($cleanupOnBackup));
		}
		return $source;
	}

	public static function setGlobalCleanupOnBackup(bool $value): void {
		self::$globalCleanupOnBackup = $value;
	}

	public static function globalShouldCleanupOnBackup(): bool {
		return self::$globalCleanupOnBackup;
	}

	public static function setGlobalMinKeepingBackupsCount(int $min): void {
		if ($min < 0) {
			throw new InvalidArgumentException("the global minimum keeping backup should zero or bigger!");
		}
		self::$globalMinKeepingBackup = $min;
	}

	public static function setTransferRetries(int $val): void {
		if ($val < 0) {
			throw new InvalidArgumentException("the global minimum keeping backup should zero or bigger!");
		}
		self::$transferRetries = $val;
	}

	public static function getGlobalMinKeepingBackupsCount(): int {
		return self::$globalMinKeepingBackup;
	}

	protected static int $transferRetries = 0;
	protected static int $globalMinKeepingBackup = 0;
	protected static bool $globalCleanupOnBackup = false;

	protected string $id;
	protected ?IBackupable $type;
	protected array $options;
	protected ?int $minKeepingBackup = null;
	protected ?bool $cleanupOnBackup = null;

	public function __construct(string $id, IBackupable $type, array $options = array()) {
		$this->id = $id;
		$this->type = $type;
		$this->options = $options;
	}

	public function getID(): string {
		return $this->id;
	}

	public function getType(): IBackupable {
		return $this->type;
	}

	public function setCleanupOnBackup(bool $value): void {
		$this->cleanupOnBackup = $value;
	}

	public function shouldCleanupOnBackup(): bool {
		if ($this->cleanupOnBackup === null) {
			return self::globalShouldCleanupOnBackup();
		}
		return $this->cleanupOnBackup;
	}

	public function setMinKeepingBackupCount(int $min): void {
		if ($min < 0) {
			throw new InvalidArgumentException("the minimum keeping backup should zero or bigger!");
		}
		$this->minKeepingBackup = $min;
	}

	public function getMinKeepingBackupCount(): int {
		if ($this->minKeepingBackup === null) {
			return self::getGlobalMinKeepingBackupsCount();
		}
		return $this->minKeepingBackup;
	}

	public function getTransferRetries(): int {
		return self::$transferRetries;
	}

	public function getOptions(): array {
		return $this->options;
	}

	public function getOption(string $name) {
		return $this->options[$name] ?? null;
	}

}
