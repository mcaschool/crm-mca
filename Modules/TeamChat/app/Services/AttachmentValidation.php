<?php

declare(strict_types=1);

namespace Modules\TeamChat\Services;

use Modules\CollaborationCore\Exceptions\ContractException;
use Symfony\Component\Process\Process;

final class AttachmentValidation
{
    /** @return array<string,mixed> */
    public function inspect(string $path, object $upload): array
    {
        $ext = strtolower(pathinfo($upload->name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = config('teamchat.attachments.types', []);
        if (! isset($allowed[$ext]) || ! in_array($mime, $allowed[$ext], true) ||
            ! in_array(explode(';', $upload->mime)[0], $allowed[$ext], true)) {
            throw new ContractException('invalid', 422);
        }
        $scan = app(\Modules\TeamChat\Integration\AttachmentScanner::class)->scan($path);
        if (! in_array($scan, ['clean', 'not_scanned'], true) || (config('teamchat.attachments.require_scan') && $scan !== 'clean')) {
            throw new ContractException('media_unavailable', 503, true);
        }
        $result = ['path' => $path, 'mime' => $mime, 'scan_status' => $scan, 'width' => null, 'height' => null, 'duration_ms' => null, 'temporary' => false];
        if ($upload->kind === 'image') {
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new ContractException('invalid', 422);
            }
            $info = @getimagesize($path);
            if (! $info || $info[0] < 1 || $info[1] < 1 || config('teamchat.attachments.max_pixels') < $info[0] * $info[1] || $info[0] > 10000 || $info[1] > 10000) {
                throw new ContractException('invalid', 422);
            }
            $image = @imagecreatefromstring(file_get_contents($path));
            if (! $image) {
                throw new ContractException('invalid', 422);
            }
            $max = (int) config('teamchat.attachments.image_edge');
            $ratio = min(1, $max / max($info[0], $info[1]));
            $width = max(1, (int) round($info[0] * $ratio));
            $height = max(1, (int) round($info[1] * $ratio));
            $clean = imagecreatetruecolor($width, $height);
            imagefill($clean, 0, 0, imagecolorallocate($clean, 255, 255, 255));
            imagecopyresampled($clean, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            $output = tempnam(sys_get_temp_dir(), 'mca-image-');
            try {
                if (! imagejpeg($clean, $output, 88)) {
                    throw new \RuntimeException('Image encoding failed');
                }
            } catch (\Throwable $e) {
                @unlink($output);
                throw $e;
            } finally {
                imagedestroy($image);
                imagedestroy($clean);
            }

            return array_replace($result, ['path' => $output, 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height, 'temporary' => true]);
        }
        if ($upload->kind === 'voice') {
            if (! in_array($ext, ['webm', 'ogg', 'mp4', 'm4a', 'wav', 'mp3'], true)) {
                throw new ContractException('invalid', 422);
            }
            $probe = $this->probe($path);
            $streams = $probe['streams'] ?? [];
            if (count($streams) !== 1 || ($streams[0]['codec_type'] ?? null) !== 'audio') {
                throw new ContractException('invalid', 422);
            }
            $output = tempnam(sys_get_temp_dir(), 'mca-audio-');
            try {
                $process = new Process([(string) config('teamchat.attachments.ffmpeg'), '-nostdin', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-i', $path,
                    '-map', '0:a:0', '-vn', '-map_metadata', '-1', '-t', (string) ((int) config('teamchat.attachments.voice_seconds') + 1),
                    '-ac', '1', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '96k', '-f', 'mp3', '-y', $output], timeout: 30);
                $process->run();
                if (! $process->isSuccessful()) {
                    throw new ContractException('invalid', 422);
                }
                $duration = (float) ($this->probe($output)['format']['duration'] ?? 0);
                if ($duration <= 0 || $duration > (int) config('teamchat.attachments.voice_seconds') + 0.2) {
                    throw new ContractException('invalid', 422);
                }

                return array_replace($result, ['path' => $output, 'mime' => 'audio/mpeg', 'duration_ms' => (int) round($duration * 1000), 'temporary' => true]);
            } catch (\Throwable $e) {
                @unlink($output);
                throw $e;
            }
        }
        if ($upload->kind !== 'document' || ! in_array($ext, ['pdf', 'txt', 'csv', 'docx', 'xlsx', 'pptx'], true)) {
            throw new ContractException('invalid', 422);
        }
        if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            $this->office($path, $ext);
        } elseif ($ext === 'pdf') {
            $bytes = file_get_contents($path);
            if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -2048), '%%EOF') ||
                preg_match('~/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA|RichMedia|XFA)\b~i', $bytes)) {
                throw new ContractException('invalid', 422);
            }
        } else {
            $bytes = file_get_contents($path);
            if (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0") || preg_match('~<\?(?:php|=)|<script|<html|<!doctype~i', $bytes)) {
                throw new ContractException('invalid', 422);
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function probe(string $path): array
    {
        $process = new Process([(string) config('teamchat.attachments.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_streams', '-show_format', '-of', 'json', $path], timeout: 10);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new ContractException('invalid', 422);
        }

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function office(string $path, string $ext): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ContractException('invalid', 422);
        }
        try {
            $root = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$ext];
            if ($zip->numFiles > 2000 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName($root) === false) {
                throw new ContractException('invalid', 422);
            }
            $total = 0;
            for ($n = 0; $n < $zip->numFiles; $n++) {
                $stat = $zip->statIndex($n);
                $name = $stat['name'];
                $total += $stat['size'];
                if (($stat['encryption_method'] ?? 0) !== 0 || $total > 100 * 1024 * 1024 || $stat['size'] > 10 * 1024 * 1024 || str_contains($name, '..') || str_contains($name, '\\') ||
                    str_starts_with($name, '/') || preg_match('~vbaProject|activeX|embeddings/|\.(exe|js|vbs|bat|cmd|ps1|html|svg)$~i', $name)) {
                    throw new ContractException('invalid', 422);
                }
                if (preg_match('~\.(xml|rels)$~i', $name)) {
                    $xml = $zip->getFromIndex($n);
                    if ($xml === false || ! mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0") || preg_match('~<!DOCTYPE|<!ENTITY|macroEnabled|TargetMode\s*=\s*["\x27]External~i', $xml)) {
                        throw new ContractException('invalid', 422);
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }
}
