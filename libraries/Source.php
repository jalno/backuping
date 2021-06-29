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

		$log->info("create new source with id: '{$id}'");
		return new self($id, $backupable, $options);
	}

	protected string $id;
	protected ?IBackupable $type;
	protected array $options;

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

	public function getOptions(): array {
		return $this->options;
	}

	public function getOption(string $name) {
		return $this->options[$name] ?? null;
	}

}
