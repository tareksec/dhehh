<?php

namespace App\Ims\Kyc;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads a Bangladeshi NID card on our own server with Tesseract (Bangla +
 * English), so the pictures never leave it (IMS-V2/TRD.md §7, old IMS §35).
 * The server needs the `tesseract-ocr` and `tesseract-ocr-ben` packages
 * (config ims.tesseract_path); without them nothing is read and staff check
 * the card by hand — a KYC is never stopped because OCR is missing.
 */
class NidOcr
{
    private const BANGLA_DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    /** Typical OCR slips inside a number (old IMS §35). */
    private const DIGIT_FIXES = ['O' => '0', 'o' => '0', 'D' => '0', 'I' => '1', 'l' => '1', '|' => '1', 'S' => '5', 's' => '5', 'B' => '8', 'Z' => '2'];

    public function available(): bool
    {
        return Cache::remember('ims.tesseract_available', now()->addHour(), function (): bool {
            try {
                $process = new Process([$this->binary(), '--version']);
                $process->setTimeout(10);
                $process->run();

                return $process->isSuccessful();
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * @return array{readable: bool, nid: string|null, date_of_birth: string|null, name: string|null}
     */
    public function read(string $imageContents): array
    {
        $empty = ['readable' => false, 'nid' => null, 'date_of_birth' => null, 'name' => null];

        if (! $this->available()) {
            return $empty;
        }

        $file = tempnam(sys_get_temp_dir(), 'nid');

        try {
            file_put_contents($file, $imageContents);
            $process = new Process([$this->binary(), $file, 'stdout', '-l', 'ben+eng']);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful() ? $this->parse($process->getOutput()) : $empty;
        } catch (Throwable $exception) {
            report($exception);

            return $empty;
        } finally {
            @unlink($file);
        }
    }

    /**
     * @return array{readable: bool, nid: string|null, date_of_birth: string|null, name: string|null}
     */
    public function parse(string $text): array
    {
        $text = strtr($text, self::BANGLA_DIGITS);
        $nid = $this->nid($text);
        $dob = $this->dateOfBirth($text);
        $name = $this->name($text);

        return ['readable' => $nid !== null || $dob !== null, 'nid' => $nid, 'date_of_birth' => $dob, 'name' => $name];
    }

    /** A 10, 13 or 17 digit NID number, after fixing look-alike letters inside number-like words. */
    public function nid(string $text): ?string
    {
        preg_match_all('/[0-9OoDIl|SsBZ][0-9OoDIl|SsBZ \-]{8,22}[0-9OoDIl|SsBZ]/u', $text, $matches);

        foreach ($matches[0] as $candidate) {
            // Only words that are mostly digits are numbers with slips in them.
            $digitsOnly = preg_replace('/\D/', '', $candidate);

            if (strlen($digitsOnly) < (int) ceil(strlen(str_replace([' ', '-'], '', $candidate)) * 0.6)) {
                continue;
            }

            $fixed = preg_replace('/\D/', '', strtr(str_replace([' ', '-'], '', $candidate), self::DIGIT_FIXES));

            if (in_array(strlen($fixed), [10, 13, 17], true)) {
                return $fixed;
            }
        }

        return null;
    }

    public function dateOfBirth(string $text): ?string
    {
        $patterns = [
            '/(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+(\d{4})/i' => 'j M Y',
            '/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/' => 'd/m/Y',
        ];

        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $text, $match)) {
                try {
                    $date = $format === 'j M Y'
                        ? CarbonImmutable::createFromFormat('j M Y', $match[1].' '.ucfirst(strtolower(substr($match[2], 0, 3))).' '.$match[3])
                        : CarbonImmutable::createFromFormat('d/m/Y', sprintf('%02d/%02d/%s', $match[1], $match[2], $match[3]));

                    if ($date !== false && $date->year > 1900 && $date->isPast()) {
                        return $date->toDateString();
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    public function name(string $text): ?string
    {
        if (preg_match('/^\s*Name\s*[:\-]?\s*(.+)$/mi', $text, $match)) {
            $name = trim(preg_replace('/[^\p{L}\s.]/u', '', $match[1]));

            return $name !== '' ? $name : null;
        }

        return null;
    }

    private function binary(): string
    {
        return (string) config('ims.tesseract_path', 'tesseract');
    }
}
