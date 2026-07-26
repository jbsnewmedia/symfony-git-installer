<?php

declare(strict_types=1);

function createDirectoryTree(string $directory, int $mode = 0o755): bool
{
    if (is_dir($directory)) {
        return true;
    }

    $directoriesToCreate = [];
    $currentDirectory = $directory;
    while (!is_dir($currentDirectory)) {
        $directoriesToCreate[] = $currentDirectory;
        $currentDirectory = dirname($currentDirectory);
    }

    foreach (array_reverse($directoriesToCreate) as $directoryToCreate) {
        if (!@mkdir($directoryToCreate, $mode) && !is_dir($directoryToCreate)) {
            return false;
        }

        @chmod($directoryToCreate, $mode);
    }

    return true;
}