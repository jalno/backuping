<?php
namespace packages\backuping\finder\Iterator;

use packages\finder\Finder\Iterator\PathFilterIterator as ParentPathFilterIterator;

/**
 * PathFilterIterator filters files by path patterns (e.g. some/special/dir).
 *
 * @author Hossein Hosni  <hosni@jeyserver.com>
 */
class PathFilterIterator extends ParentPathFilterIterator
{
    /**
     * Filters the iterator values.
     *
     * @return bool true if the value should be kept, false otherwise
     */
    public function accept(): bool {
        $filename = $this->current()->getPath();

        if ('\\' === \DIRECTORY_SEPARATOR) {
            $filename = str_replace('\\', '/', $filename);
        }

        return $this->isAccepted($filename);
    }
}
