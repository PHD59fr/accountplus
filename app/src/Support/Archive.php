<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Archive
{
    private const MAX_ARCHIVE_SIZE = 67_108_864;
    private const MAX_UNCOMPRESSED_SIZE = 67_108_864;
    private const MAX_ENTRY_SIZE = 33_554_432;
    private const MAX_ENTRIES = 64;

    public static function write(array $entries, string $path): void
    {
        $out = '';
        $central = '';
        $offset = 0;

        foreach ($entries as $name => $content) {
            $content = (string) $content;
            $nameLen = strlen($name);
            $crc = crc32($content);
            $size = strlen($content);
            $compressed = (string) gzdeflate($content);
            $compSize = strlen($compressed);

            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $compSize, $size, $nameLen, 0);
            $out .= $name;
            $out .= $compressed;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, $compSize, $size, $nameLen, 0, 0, 0, 0, 0, $offset);
            $central .= $name;

            $offset += 30 + $nameLen + $compSize;
        }

        $cdOffset = strlen($out);
        $out .= $central;
        $out .= pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $cdOffset, 0);

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create archive directory.');
        }
        if (file_put_contents($path, $out) === false || !chmod($path, 0600)) {
            throw new RuntimeException('Could not write archive file.');
        }
    }

    public static function read(
        string $path,
        int $maxArchiveSize = self::MAX_ARCHIVE_SIZE,
        int $maxUncompressedSize = self::MAX_UNCOMPRESSED_SIZE
    ): array
    {
        if ($maxArchiveSize < 1 || $maxUncompressedSize < 1) {
            throw new RuntimeException('Archive size limits must be positive.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Archive file is not readable.');
        }
        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize > $maxArchiveSize) {
            throw new RuntimeException('Archive file exceeds the allowed size.');
        }
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            throw new RuntimeException('Archive file could not be read.');
        }

        $eocdPos = self::findEndOfCentralDirectory($data);
        if ($eocdPos === null) {
            throw new RuntimeException('Invalid archive: end of central directory record not found.');
        }

        $eocd = unpack('Vsig/vdisk/vcdDisk/ventriesDisk/ventriesTotal/VcdSize/VcdOffset/vcommentLen', substr($data, $eocdPos, 22));
        $entryCount = (int) $eocd['entriesTotal'];
        if (
            (int) $eocd['disk'] !== 0
            || (int) $eocd['cdDisk'] !== 0
            || (int) $eocd['entriesDisk'] !== $entryCount
            || $entryCount > self::MAX_ENTRIES
        ) {
            throw new RuntimeException('Invalid or unsupported archive structure.');
        }
        $cdSize = (int) $eocd['cdSize'];
        $end = (int) $eocd['cdOffset'] + $cdSize;
        if ($end !== $eocdPos || $end > strlen($data)) {
            throw new RuntimeException('Invalid archive central directory.');
        }

        $entries = [];
        $pos = (int) $eocd['cdOffset'];
        $totalSize = 0;
        $readEntries = 0;

        while ($pos + 46 <= $end) {
            $header = unpack('Vsig/vmadeBy/vneeded/vflag/vmethod/vtime/vdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($data, $pos, 46));
            if (($header['sig'] ?? 0) !== 0x02014b50) {
                throw new RuntimeException('Invalid archive central directory entry.');
            }

            $nameLength = (int) $header['nameLen'];
            $recordLength = 46 + $nameLength + (int) $header['extraLen'] + (int) $header['commentLen'];
            if ($nameLength < 1 || $nameLength > 255 || $pos + $recordLength > $end) {
                throw new RuntimeException('Invalid archive entry metadata.');
            }
            $name = substr($data, $pos + 46, $nameLength);
            if (
                preg_match('/[\x00-\x1f\x7f]/', $name) === 1
                || str_contains($name, '\\')
                || preg_match('/^[A-Za-z]:/', $name) === 1
                || str_starts_with($name, '/')
                || preg_match('#(^|[\\/])\.\.([\\/]|$)#', $name) === 1
                || array_key_exists($name, $entries)
            ) {
                throw new RuntimeException('Invalid or duplicate archive entry name.');
            }
            $unixType = ((int) $header['extAttr'] >> 16) & 0170000;
            if ($unixType !== 0 && $unixType !== 0100000) {
                throw new RuntimeException('Non-regular archive entries are not supported.');
            }
            $compSize = (int) $header['compSize'];
            $size = (int) $header['size'];
            $method = (int) $header['method'];
            if (($header['flag'] & 1) !== 0 || $size > self::MAX_ENTRY_SIZE) {
                throw new RuntimeException('Encrypted or oversized archive entry.');
            }
            $totalSize += $size;
            if ($totalSize > $maxUncompressedSize) {
                throw new RuntimeException('Archive contents exceed the allowed size.');
            }

            $localOffset = (int) $header['offset'];
            if ($localOffset < 0 || $localOffset + 30 > strlen($data)) {
                throw new RuntimeException('Invalid archive local entry offset.');
            }
            $local = unpack('Vsig/vneeded/vflag/vmethod/vtime/vdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen', substr($data, $localOffset, 30));
            if (($local['sig'] ?? 0) !== 0x04034b50 || (int) $local['method'] !== $method) {
                throw new RuntimeException('Invalid archive local entry.');
            }
            $dataStart = $localOffset + 30 + (int) $local['nameLen'] + (int) $local['extraLen'];
            $localName = substr($data, $localOffset + 30, (int) $local['nameLen']);
            if ($localName !== $name || $dataStart < 0 || $dataStart + $compSize > (int) $eocd['cdOffset']) {
                throw new RuntimeException('Invalid archive entry data bounds.');
            }
            $compressed = substr($data, $dataStart, $compSize);

            if ($method === 0) {
                $entries[$name] = $compressed;
            } elseif ($method === 8) {
                $inflated = @gzinflate($compressed, max(1, $size));
                if ($inflated === false) {
                    throw new RuntimeException('Could not decompress archive entry.');
                }
                $entries[$name] = $inflated;
            } else {
                throw new RuntimeException('Unsupported archive compression method.');
            }
            if (
                strlen($entries[$name]) !== $size
                || (int) sprintf('%u', crc32($entries[$name])) !== (int) $header['crc']
            ) {
                throw new RuntimeException('Archive entry failed its integrity check.');
            }

            $pos += $recordLength;
            $readEntries++;
        }

        if ($pos !== $end || $readEntries !== $entryCount) {
            throw new RuntimeException('Archive entry count does not match its directory.');
        }

        return $entries;
    }

    private static function findEndOfCentralDirectory(string $data): ?int
    {
        $length = strlen($data);
        $searchStart = max(0, $length - 65557);
        for ($pos = $length - 22; $pos >= $searchStart; $pos--) {
            if (
                $data[$pos] === "\x50"
                && $data[$pos + 1] === "\x4b"
                && $data[$pos + 2] === "\x05"
                && $data[$pos + 3] === "\x06"
            ) {
                $commentLen = unpack('v', substr($data, $pos + 20, 2))[1];
                if ($pos + 22 + $commentLen === $length) {
                    return $pos;
                }
            }
        }
        return null;
    }
}
