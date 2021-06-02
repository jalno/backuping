<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\{Log};

class Source {

	public static function fromArray(array $arr): self {
		$log = Log::getInstance();
		$log->info("Source::fromArray");

		$id = $arr["id"] ?? null;
		if (!$id) {
			$log->error("you should pass an unique 'id' (string) index");
			throw new InvalidArgumentException("you should pass n unique 'id' (string) index");
		}
		if (!is_string($id)) {
			$log->error("the given 'id' index is not string!");
			throw new InvalidArgumentException("the given 'id' index is not string!");
		}

		$options = $arr["options"] ?? [];
		if (!is_array($options)) {
			$log->error("the given 'options' index is not array!");
			throw new InvalidArgumentException("the given 'options' index is not array!");
		}

		$type = $arr["type"] ?? null;
		if (!$type) {
			$log->error("you should pass 'type' index that is instance of '" . IBackupable::class . "' or callable that return this type");
			throw new InvalidArgumentException("you should pass 'type' index that is instance of '" . IBackupable::class . "' or callable that return this type");
		}
		if (is_callable($type)) {
			$log->info("type is callable, so call it");
			$type = $type($options);
		}
		if (!($type instanceof IBackupable)) {
			$log->error("the 'type' index should be instance of '" . IBackupable::class . "' or callable that return this type, (" . gettype($type) . ") given!");
			throw new InvalidArgumentException("the 'type' index should be instance of '" . IBackupable::class . "' or callable that return this type, (" . gettype($type) . ") given!");
		}

		return new self($id, $type, $options);
	}

	public function __construct(string $id, IBackupable $backupable, ?array $options = null) {
		$this->id = $id;
		$this->backupable = $backupable;
		$this->options = $options;
	}

	protected string $id;
	protected ?IBackupable $type;
	protected ?array $options = array();

	public function getID(): string {
		return $this->id;
	}

	public function getBackupable(): IBackupable {
		return $this->backupable;
	}

	public function getOptions(): array {
		return $this->options ?? [];
	}

	public function getOption(string $name) {
		return $this->options[$name] ?? null;
	}

}
