<?php

namespace App\Services;

use App\Models\Personnel;
use App\Support\Files;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private staging objects are never record paths and cannot be viewed. */
class DirectUploads
{
    public function sign(Personnel $person, array $data): array
    {
        $key = '_staging/'.now()->format('Y-m-d').'/'.Str::uuid();
        $signed = Files::disk()->temporaryUploadUrl($key, now()->addMinutes(10), [
            'ContentType' => 'application/octet-stream',
        ]);
        // PHP's usual file/image validation still runs against the real bytes
        // when the record form is submitted, not the client's MIME claim.
        $ticket = Crypt::encryptString(json_encode([
            'key' => $key, 'owner' => $person->getKey(), 'campus' => $person->campus_id,
            'name' => basename(str_replace('\\', '/', $data['name'])), 'size' => $data['size'],
            'field' => $data['field'], 'target' => $data['target'], 'method' => strtoupper($data['method']),
            'expires' => now()->addHour()->timestamp,
        ], JSON_THROW_ON_ERROR));

        return ['url' => $signed['url'], 'headers' => $signed['headers'], 'ticket' => $ticket];
    }

    public function hydrate(string $ticket, Personnel $person, string $target, string $method): array
    {
        try {
            $data = json_decode(Crypt::decryptString($ticket), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->invalid();
        }
        // campus is null for an Admin (no campus of their own): present, and equal, is what counts.
        if (! is_array($data) || ! isset($data['owner'], $data['expires'], $data['target'], $data['method'], $data['key'], $data['field'], $data['size'], $data['name'])
            || ! array_key_exists('campus', $data)
            || ($data['campus'] === null ? null : (int) $data['campus']) !== ($person->campus_id === null ? null : (int) $person->campus_id)
            || (int) $data['owner'] !== (int) $person->getKey()
            || $data['expires'] < now()->timestamp || $data['target'] !== $target || $data['method'] !== $method
            || ! str_starts_with($data['key'], '_staging/')) {
            $this->invalid();
        }
        $disk = Files::disk();
        if (! $disk->exists($data['key']) || $disk->size($data['key']) !== $data['size'] || $data['size'] > config('hosting.upload_max_bytes')) {
            $this->invalid($data['field']);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'drrms-upload-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not prepare the uploaded file.');
        }
        $input = $output = null;
        $failed = false;
        try {
            $input = $disk->readStream($data['key']);
            $output = fopen($temporary, 'wb');
            if (! is_resource($input) || ! is_resource($output)
                || stream_copy_to_stream($input, $output, config('hosting.upload_max_bytes') + 1) !== $data['size']) {
                $this->invalid($data['field']);
            }
        } catch (\Throwable $e) {
            $failed = true;
            throw $e;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if ($failed) {
                @unlink($temporary);
            }
        }

        return [$data['field'], new UploadedFile($temporary, $data['name'], null, UPLOAD_ERR_OK, true), $temporary];
    }

    private function invalid(string $field = 'upload'): never
    {
        throw ValidationException::withMessages([$field => 'This upload expired or could not be verified. Select the file again and retry.']);
    }

    public function cleanExpired(): int
    {
        $removed = 0;
        $disk = Files::disk();
        foreach ($disk->allFiles('_staging') as $path) {
            if ($disk->lastModified($path) < now()->subHours(2)->timestamp && $disk->delete($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
