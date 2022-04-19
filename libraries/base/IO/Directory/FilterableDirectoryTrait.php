<?php

namespace packages\backuping\IO\Directory;

use ArrayObject;
use packages\base\IO\{Directory, File, Node};
use packages\backuping\finder\Iterator\PathFilterIterator;

trait FilterableDirectoryTrait {

	protected Directory $node;

	/** @var string[] */
	protected array $filterPaths = [];

	/** @var string[] */
	protected array $filterNotPaths = [];

	public function __construct(Directory $node) {
		$this->node = $node;
	}

	public function getNode(): Node {
		return $this->node;
	}

	/**
	 * Adds rules that filenames must match.
	 *
	 * You can use patterns (delimited with / sign) or simple strings.
	 *
	 *     $finder->path('some/special/dir')
	 *     $finder->path('/some\/special\/dir/') // same as above
	 *     $finder->path(['some dir', 'another/dir'])
	 *
	 * Use only / as dirname separator.
	 *
	 * @param string[] $patterns A pattern (a regexp or a string) or an array of patterns
	 */
	public function filterPaths(array $patterns): self
	{
		$this->filterPaths = array_merge($this->filterPaths, $patterns);

		return $this;
	}

	/**
	 * Adds rules that filenames must not match.
	 *
	 * You can use patterns (delimited with / sign) or simple strings.
	 *
	 *     $finder->notPath('some/special/dir')
	 *     $finder->notPath('/some\/special\/dir/') // same as above
	 *     $finder->notPath(['some/file.txt', 'another/file.log'])
	 *
	 * Use only / as dirname separator.
	 *
	 * @param string[] $patterns A pattern (a regexp or a string) or an array of patterns
	 *
	 */
	public function filterNotPaths(array $patterns)
	{
		$this->filterNotPaths = array_merge($this->filterNotPaths, $patterns);

		return $this;
	}

	/**
	 * @return File[]
	 */
	public function files(bool $recursively = true): array {
		if ($this->filterPaths or $this->filterNotPaths) {
			return iterator_to_array(new PathFilterIterator(
				(new ArrayObject($this->node->files($recursively)))->getIterator(),
				$this->filterPaths,
				$this->filterNotPaths
			));
		}
		return $this->node->files($recursively);
	}

	public function items(bool $recursively = true): array {
		if ($this->filterPaths or $this->filterNotPaths) {
			return iterator_to_array(new PathFilterIterator(
				(new ArrayObject($this->node->items($recursively)))->getIterator(),
				$this->filterPaths,
				$this->filterNotPaths
			));
		}
		return $this->node->items($recursively);
	}

	/**
	 * @return Directory[]
	 */
	public function directories(bool $recursively = true): array {
		if ($this->filterPaths or $this->filterNotPaths) {
			return iterator_to_array(new PathFilterIterator(
				(new ArrayObject($this->node->directories($recursively)))->getIterator(),
				$this->filterPaths,
				$this->filterNotPaths
			));
		}
		return $this->node->directories($recursively);
	}

	public function size(): int {
		return array_reduce(
			$this->files(true),
			fn (int $carry, File $file) => $carry + $file->size(),
			0
		);
	}

	public function getPath(): string {
		return $this->node->getPath();
	}

	public function getDirectory(): Directory {
		return $this->node->getDirectory();
	}

	public function exists(): bool {
		return $this->node->exists();
	}
}
