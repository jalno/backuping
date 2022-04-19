<?php

namespace packages\backuping\IO\Directory;

use ArrayObject;
use packages\base\IO\{Directory, Exception, File, Node};
use packages\backuping\finder\Iterator\PathFilterIterator;

class FilterableLocalDirectory extends Directory\Local implements \Serializable {
	use FilterableDirectoryTrait;

	public function __construct(Directory\Local $node) {
		parent::__construct($node->getPath());
		$this->node = $node;
	}

	public function getRelativePath(Directory $parent): string {
		return $this->node->getRelativePath($parent);
	}

	public function isIn(Directory $parent): bool {
		return $this->node->isIn($parent);
	}

	public function make(bool $recursive = false): bool {
		throw new Exception(sprintf('%s does not support\'s %s functionality, it is readonly!', __CLASS__, __FUNCTION__));
		// return $this->node->make();
	}

	public function file(string $name): File\Local {
		// throw new Exception(sprintf('%s does not support\'s %s functionality, it is readonly!', __CLASS__, __FUNCTION__));
		return $this->node->file($name);
	}

	public function directory(string $name): Directory\Local {
		// throw new Exception(sprintf('%s does not support\'s %s functionality, it is readonly!', __CLASS__, __FUNCTION__));
		return $this->node->directory($name);
	}

	public function getDirectory(): Directory\Local {
		return $this->node->getDirectory();
	}

	public function move(Directory $dest): bool {
		// throw new Exception(sprintf('%s does not support\'s %s functionality, it is readonly!', __CLASS__, __FUNCTION__));
		return $this->node->move($dest);
	}

	public function rename(string $newName): bool {
		// throw new Exception(sprintf('%s does not support\'s %s functionality, it is readonly!', __CLASS__, __FUNCTION__));
		$result = $this->node->rename($newName);
		if ($result) {
			$this->basename = $newName;
		}
		return $result;
	}

	public function delete() {
		// throw new Exception(sprintf('%s does not support\'s %s functionality, it is readonly!', __CLASS__, __FUNCTION__));
		$this->node->delete();
	}

	public function serialize(): string {
		return $this->node->serialize();
	}

	public function unserialize($serialized) {
		$this->node->unserialize($serialized);
		$data = unserialize($serialized);
		$this->directory = isset($data['directory']) ? $data['directory'] : null;
		$this->basename = isset($data['basename']) ? $data['basename'] : null;
	}
}
