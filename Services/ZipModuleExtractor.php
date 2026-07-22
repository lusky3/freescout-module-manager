<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\InstallResult;

class ZipModuleExtractor
{
    /**
     * Ceiling on the total uncompressed size (bytes) of every entry in a
     * module ZIP, checked before extraction. A small, highly-compressed
     * archive can otherwise decompress to something enormous (a "zip
     * bomb"), exhausting disk space. Set well above the controller's 50MB
     * upload cap, since a legitimate module with a vendor/ directory of
     * dependencies can reasonably uncompress to more than its compressed
     * upload size, but still low enough to catch an actual high-ratio
     * compression bomb.
     */
    public const MAX_UNCOMPRESSED_BYTES = 500 * 1024 * 1024; // 500MB

    /**
     * POSIX S_IFMT: the bit-mask that isolates the file-type bits from a
     * Unix file mode (which packs permission bits and type bits together).
     */
    private const UNIX_FILE_TYPE_MASK = 0170000;

    /**
     * POSIX S_IFLNK: the file-type value — once isolated via
     * UNIX_FILE_TYPE_MASK — that identifies a symbolic link.
     */
    private const UNIX_SYMLINK_TYPE = 0120000;

    private string $modulesDir;

    public function __construct(string $modulesDir)
    {
        $this->modulesDir = $modulesDir;
    }

    public function extract(string $zipPath): InstallResult
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return InstallResult::fail('Could not open ZIP archive.');
        }

        // These are populated during validation below and consumed after
        // the try/finally block (for the atomic rename), once the ZipArchive
        // handle has been closed. PHP keeps variables assigned inside a
        // try block alive in the enclosing function scope after the block
        // exits, so this is safe as long as every early return happens
        // before a variable is relied upon.
        $topFolder = null;
        $moduleInfo = null;
        $stagingDir = null;
        $destination = null;

        try {
            $topFolder = $this->findSingleTopLevelFolder($zip);
            if ($topFolder === null) {
                return InstallResult::fail('ZIP must contain exactly one top-level folder.');
            }

            $traversalEntry = $this->findPathTraversalEntry($zip);
            if ($traversalEntry !== null) {
                return InstallResult::fail("Unsafe path in ZIP entry: {$traversalEntry}");
            }

            $symlinkEntry = $this->findSymlinkEntry($zip);
            if ($symlinkEntry !== null) {
                return InstallResult::fail("Unsafe ZIP entry: '{$symlinkEntry}' is a symlink, which is not allowed.");
            }

            $totalUncompressedSize = $this->sumUncompressedSize($zip);
            if ($totalUncompressedSize > self::MAX_UNCOMPRESSED_BYTES) {
                return InstallResult::fail(
                    'ZIP archive is too large when uncompressed (over '.self::MAX_UNCOMPRESSED_BYTES.' bytes); refusing to extract.'
                );
            }

            $moduleInfo = $this->readModuleJson($zip, $topFolder);
            if ($moduleInfo === null) {
                return InstallResult::fail("ZIP is missing a valid {$topFolder}/module.json with 'name' and 'alias'.");
            }

            $destination = $this->modulesDir.'/'.$topFolder;
            if (is_dir($destination)) {
                return InstallResult::fail("A module folder named '{$topFolder}' already exists.");
            }

            $stagingDir = $this->modulesDir.'/.staging-'.bin2hex(random_bytes(8));
            if (!mkdir($stagingDir, 0777, true)) {
                return InstallResult::fail('Could not create a staging directory for extraction.');
            }

            $extracted = $zip->extractTo($stagingDir);

            if (!$extracted) {
                $this->removeDirectory($stagingDir);

                return InstallResult::fail('Could not extract ZIP archive.');
            }
        } finally {
            // Runs on every path out of the try block above — normal
            // fallthrough, an early return, or an uncaught exception — so
            // the ZipArchive handle is closed exactly once no matter which
            // validation step failed, without repeating close() calls at
            // each return site.
            $zip->close();
        }

        // The ZIP handle is closed from here on; everything below is pure
        // filesystem work using the values captured above.
        $stagedModuleDir = $stagingDir.'/'.$topFolder;
        if (!is_dir($stagedModuleDir)) {
            $this->removeDirectory($stagingDir);

            return InstallResult::fail('Could not extract ZIP archive.');
        }

        // Atomically move the extracted module folder into place. rename() on
        // the same filesystem is atomic and fails if a same-named directory
        // is concurrently created at the destination first, turning a race
        // between two simultaneous installs into a clean failure instead of
        // silently corrupting either module folder.
        if (!@rename($stagedModuleDir, $destination)) {
            $this->removeDirectory($stagingDir);

            return InstallResult::fail("A module folder named '{$topFolder}' already exists.");
        }

        $this->removeDirectory($stagingDir);

        return InstallResult::ok($moduleInfo['alias'], $moduleInfo['name'], $topFolder);
    }

    private function findSingleTopLevelFolder(\ZipArchive $zip): ?string
    {
        $top = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                continue;
            }

            $firstSegment = explode('/', $name)[0];
            if ($firstSegment === '') {
                continue;
            }

            if ($top === null) {
                $top = $firstSegment;
            } elseif ($top !== $firstSegment) {
                return null;
            }
        }

        return $top;
    }

    private function findPathTraversalEntry(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            if ($this->isUnsafePath($name)) {
                return $name;
            }
        }

        return null;
    }

    private function isUnsafePath(string $name): bool
    {
        if (strpos($name, '/') === 0 || strpos($name, "\\") !== false) {
            return true;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects ZIP entries that carry a Unix "symlink" file-type mode in
     * their external file attributes. ZipArchive::extractTo() extracts
     * entries by name, but a name that passes the traversal checks can
     * still be paired with symlink attributes; if that symlink were ever
     * materialized on disk (directly by extraction, or by a different
     * unzip implementation reading this same archive) and pointed outside
     * modulesDir, a later entry written "through" it could escape the
     * intended destination even though every entry NAME was safe. Reject
     * any such entry outright.
     */
    private function findSymlinkEntry(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $opsys = 0;
            $attr = 0;

            if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                continue;
            }

            if ($opsys !== \ZipArchive::OPSYS_UNIX) {
                continue;
            }

            $mode = ($attr >> 16) & 0xFFFF;
            $fileType = $mode & self::UNIX_FILE_TYPE_MASK;

            if ($fileType === self::UNIX_SYMLINK_TYPE) {
                $name = $zip->getNameIndex($i);
                return $name !== false ? $name : "entry #{$i}";
            }
        }

        return null;
    }

    /**
     * Sums the uncompressed size of every entry as reported by the ZIP's
     * own metadata (statIndex), without extracting anything. This is the
     * cheap check that stands between a small, highly-compressed archive
     * and a disk-exhausting extraction.
     */
    private function sumUncompressedSize(\ZipArchive $zip): int
    {
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false || !isset($stat['size'])) {
                continue;
            }

            $total += $stat['size'];
        }

        return $total;
    }

    private function readModuleJson(\ZipArchive $zip, string $topFolder): ?array
    {
        $contents = $zip->getFromName($topFolder.'/module.json');
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || empty($data['name']) || empty($data['alias'])) {
            return null;
        }

        return [
            'name' => (string) $data['name'],
            'alias' => (string) $data['alias'],
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
