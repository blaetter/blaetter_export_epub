<?php

namespace Drupal\blaetter_export_epub;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Hands an exported ePub over to pepgen.
 *
 * pepgen reads the original ePubs unzipped from its original directory, one directory "<id>.epub" per issue. The ePub
 * is unzipped into a hidden directory next to it first and then swapped in, so pepgen never sees a half written ePub.
 * The previous version is kept as hidden directory ".<id>.epub.previous" (only the last one). A lock per issue keeps
 * two hand overs of the same issue (export page, drush) from interfering.
 */
class PepgenPublisher
{
    /**
     * How long the lock of an issue is held at most, in seconds.
     */
    const LOCK_TIMEOUT = 120.0;

    /**
     * The original directory of pepgen.
     *
     * @var string
     */
    private $directory;

    /**
     * The lock backend.
     *
     * @var \Drupal\Core\Lock\LockBackendInterface
     */
    private $lock;

    /**
     * @param string $directory
     *   The original directory of pepgen (epub_original_dir), absolute.
     * @param \Drupal\Core\Lock\LockBackendInterface $lock
     *   The lock backend, e.g. the service "lock".
     */
    public function __construct(string $directory, LockBackendInterface $lock)
    {
        $this->directory = rtrim($directory, '/');
        $this->lock = $lock;
    }

    /**
     * Checks, that the directory can be used, and returns the problem otherwise.
     *
     * @param string $directory
     *   The directory to check.
     *
     * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
     *   The problem, or null if the directory can be used.
     */
    public static function checkDirectory(string $directory)
    {
        if ('' === $directory || '/' !== $directory[0]) {
            return new TranslatableMarkup('The directory has to be an absolute path.');
        }
        if (!is_dir($directory)) {
            return new TranslatableMarkup('The directory does not exist.');
        }
        if (!is_writable($directory)) {
            return new TranslatableMarkup('The directory is not writable for the web server.');
        }
        return null;
    }

    /**
     * Unzips the ePub into the original directory of pepgen and replaces an existing version.
     *
     * @param string $epub_data
     *   The ePub (zip archive) in binary form.
     * @param int|string $id
     *   The id of the ePub, the node id of the issue.
     *
     * @return string
     *   The directory of the ePub.
     *
     * @throws \RuntimeException
     *   If the ePub could not be unzipped or swapped in; an existing version is kept then.
     */
    public function publish(string $epub_data, $id)
    {
        if (!preg_match('/^\d+$/', (string) $id)) {
            throw new \RuntimeException('The id of the ePub has to be a number.');
        }
        $problem = self::checkDirectory($this->directory);
        if (null !== $problem) {
            throw new \RuntimeException((string) $problem);
        }

        $lock_name = 'blaetter_export_epub:pepgen:' . $id;
        if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
            // another hand over of this issue is running, wait for it once
            $this->lock->wait($lock_name, (int) self::LOCK_TIMEOUT);
            if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT)) {
                throw new \RuntimeException('The ePub of this issue is being replaced right now, please try again later.');
            }
        }
        try {
            return $this->swapIn($epub_data, (string) $id);
        } finally {
            $this->lock->release($lock_name);
        }
    }

    /**
     * Unzips the ePub next to the current one and swaps it in, while holding the lock of the issue.
     */
    private function swapIn(string $epub_data, string $id)
    {
        $target = $this->directory . '/' . $id . '.epub';
        $previous = $this->directory . '/.' . $id . '.epub.previous';
        $temp = $this->directory . '/.' . $id . '.epub.new-' . bin2hex(random_bytes(4));

        // an earlier run was interrupted between the two renames: bring the previous version back first
        if (!file_exists($target) && is_dir($previous) && !rename($previous, $target)) {
            throw new \RuntimeException('The previous ePub could not be restored: ' . $previous);
        }

        $zip_file = tempnam(sys_get_temp_dir(), 'blaetter-epub-');
        if (false === $zip_file) {
            throw new \RuntimeException('No temporary file could be created.');
        }
        try {
            if (false === file_put_contents($zip_file, $epub_data)) {
                throw new \RuntimeException('The ePub could not be written to a temporary file.');
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($zip_file)) {
                throw new \RuntimeException('The ePub is not a valid zip archive.');
            }
            try {
                if (false === $zip->locateName('mimetype') || false === $zip->locateName('META-INF/container.xml')) {
                    throw new \RuntimeException(
                        'The zip archive is no ePub (mimetype or META-INF/container.xml missing).'
                    );
                }
                // the zip is our own export; extractTo() also makes entry names relative (strips "../")
                if (!mkdir($temp, 0775)) {
                    throw new \RuntimeException('The directory for the new ePub could not be created: ' . $temp);
                }
                if (!$zip->extractTo($temp)) {
                    self::remove($temp);
                    throw new \RuntimeException('The ePub could not be unzipped.');
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($zip_file);
        }

        // swap: the current version becomes the previous one, the new one takes its place
        if (is_dir($target)) {
            if (!self::remove($previous)) {
                self::remove($temp);
                throw new \RuntimeException('The previous backup could not be removed: ' . $previous);
            }
            if (!rename($target, $previous)) {
                self::remove($temp);
                throw new \RuntimeException('The existing ePub could not be moved aside: ' . $target);
            }
        }
        if (!rename($temp, $target)) {
            // put the previous version back
            $restored = is_dir($previous) && !file_exists($target) && rename($previous, $target);
            self::remove($temp);
            throw new \RuntimeException(
                'The new ePub could not be moved into place'
                . ($restored ? ', the previous one is in place again.' : '. The previous one is ' . $previous . '.')
            );
        }
        return $target;
    }

    /**
     * Removes a directory recursively; a path that does not exist counts as removed.
     *
     * Symbolic links are removed, not followed.
     *
     * @param string $path
     *   The directory.
     *
     * @return bool
     *   Whether the path is gone.
     */
    private static function remove(string $path)
    {
        if (is_link($path) || is_file($path)) {
            return unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }
        $removed = true;
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $removed = self::remove($path . '/' . $entry) && $removed;
            }
        }
        return $removed && rmdir($path);
    }
}
