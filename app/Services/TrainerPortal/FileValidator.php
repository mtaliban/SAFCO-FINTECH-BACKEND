<?php

namespace App\Services\TrainerPortal;

use Illuminate\Http\UploadedFile;

/**
 * SRS Module 13 — defense-in-depth file validation.
 *
 * Client-provided MIME type is not trusted. We inspect the actual file bytes
 * (magic numbers) to confirm PDF or common image formats.
 *
 * This is a second layer AFTER Laravel's mimetypes validation — an attacker
 * could rename evil.php.jpg to fool the extension check but cannot forge
 * the leading file bytes.
 */
class FileValidator
{
    private const SIGNATURES = [
        'application/pdf' => ['bin' => "%PDF-", 'offset' => 0],
        'image/jpeg'      => ['bin' => "\xFF\xD8\xFF", 'offset' => 0],
        'image/png'       => ['bin' => "\x89PNG\r\n\x1A\n", 'offset' => 0],
        'image/webp'      => ['bin' => "RIFF", 'offset' => 0, 'secondary' => ['bin' => "WEBP", 'offset' => 8]],
    ];

    /**
     * @throws \DomainException when file bytes don't match a supported signature.
     */
    public static function assertSafeProofFile(UploadedFile $file): void
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if (!$handle) {
            throw new \DomainException('Uploaded file is not readable.');
        }
        $head = fread($handle, 16);
        fclose($handle);

        foreach (self::SIGNATURES as $mime => $sig) {
            $expected = $sig['bin'];
            $offset = $sig['offset'];
            if (substr($head, $offset, strlen($expected)) !== $expected) continue;

            // Optional secondary signature (e.g. WEBP requires "RIFF" + "WEBP" at offset 8)
            if (isset($sig['secondary'])) {
                $sec = $sig['secondary'];
                if (substr($head, $sec['offset'], strlen($sec['bin'])) !== $sec['bin']) continue;
            }
            return; // matched a known-safe signature
        }

        throw new \DomainException(
            'File contents do not match a supported format (PDF/JPEG/PNG/WEBP). Rename or extension spoofing rejected.'
        );
    }
}
