<?php

namespace App\Http\Controllers;

use App\Services\MobileVisionPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudioUploadController
{
    public function index(Request $request)
    {
        return ['data' => DB::table('studio_uploads')->where('user_id', $request->user()->id)->latest('id')->limit(100)->get(['id', 'name', 'mime', 'bytes', 'created_at'])];
    }

    public function create(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'data' => 'required|string|max:4000000', 'text' => 'nullable|string|max:50000', 'image_preview' => 'nullable|string|max:175000']);
        $name = basename(str_replace('\\', '/', $data['name']));
        abort_if(preg_match('/[\x00-\x1f\x7f]/', $name), 422, 'Invalid filename.');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        abort_unless(in_array($ext, ['txt', 'md', 'markdown', 'csv', 'json', 'pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp'], true), 422, 'Unsupported reference file.');
        $bytes = base64_decode($data['data'], true);
        abort_unless($bytes !== false && strlen($bytes) > 0 && strlen($bytes) <= 3000000, 422, 'Upload files up to 3 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $image = in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true);
        if ($image) {
            $info = @getimagesizefromstring($bytes);
            $expected = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext];
            abort_unless($info && $mime === $expected && ($info['mime'] ?? '') === $expected && $info[0] * $info[1] <= 16777216, 422, 'Invalid or oversized image.');
            abort_unless(! empty($data['image_preview']), 422, 'Image preview is required.');
            MobileVisionPayload::validate([['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => $data['image_preview']]]]]]);
        } else {
            abort_if(! empty($data['image_preview']), 422, 'Only images may include an image preview.');
            abort_unless(trim($data['text'] ?? '') !== '' && strlen($data['text']) <= 150000, 422, 'Extract readable text before uploading the document. Scanned PDFs require OCR.');
            if ($ext === 'pdf') {
                abort_unless(str_starts_with($bytes, '%PDF-') && $mime === 'application/pdf', 422, 'Invalid PDF.');
            } elseif ($ext === 'doc') {
                abort_unless(str_starts_with($bytes, hex2bin('d0cf11e0a1b11ae1')), 422, 'Invalid Word document.');
            } elseif ($ext === 'docx') {
                $this->validateDocx($bytes);
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            } else {
                abort_unless(mb_check_encoding($bytes, 'UTF-8') && ! str_contains($bytes, "\0"), 422, 'Text references must be UTF-8.');
            }
        }
        $path = 'studio/'.$request->user()->id.'/'.Str::uuid().'.bin';
        $id = DB::transaction(function () use ($request, $name, $bytes, $mime, $data, $image, $path) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            abort_if(DB::table('studio_uploads')->where('user_id', $request->user()->id)->sum('bytes') + strlen($bytes) > 50000000, 422, 'Private reference storage is full.');
            Storage::disk('local')->put($path, $bytes);
            try {
                return DB::table('studio_uploads')->insertGetId(['user_id' => $request->user()->id, 'name' => $name, 'mime' => $mime, 'private_path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'text' => $image ? null : $data['text'], 'image_preview' => $image ? $data['image_preview'] : null, 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });

        return response()->json(['id' => $id, 'name' => $name, 'mime' => $mime, 'bytes' => strlen($bytes)], 201);
    }

    private function validateDocx(string $bytes): void
    {
        // libmagic versions on shared hosts disagree about valid Office ZIP MIME types.
        // Inspect bounded OOXML entries without extracting files or resolving entities.
        $temporary = tmpfile();
        abort_unless($temporary, 503, 'Document validation unavailable.');
        $zip = new \ZipArchive;
        $opened = false;
        try {
            fwrite($temporary, $bytes);
            $path = stream_get_meta_data($temporary)['uri'];
            $opened = $zip->open($path, \ZipArchive::RDONLY) === true;
            abort_unless($opened, 422, 'Invalid DOCX.');
            abort_unless($zip->numFiles <= 2000, 422, 'Oversized DOCX archive.');
            foreach (['[Content_Types].xml', 'word/document.xml'] as $name) {
                $entry = $zip->statName($name);
                abort_unless($entry && $entry['size'] > 0 && $entry['size'] <= 8000000, 422, 'Invalid or oversized DOCX.');
                $xml = $zip->getFromName($name);
                abort_unless(is_string($xml) && ! preg_match('/<!DOCTYPE|<!ENTITY/i', $xml), 422, 'Invalid DOCX XML.');
                $document = new \DOMDocument;
                abort_unless(@$document->loadXML($xml, LIBXML_NONET), 422, 'Invalid DOCX XML.');
                if ($name === 'word/document.xml') {
                    abort_unless($document->documentElement?->localName === 'document' && in_array($document->documentElement?->namespaceURI, ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'], true), 422, 'Invalid Word document.');
                } else {
                    abort_unless($document->documentElement?->localName === 'Types' && $document->documentElement?->namespaceURI === 'http://schemas.openxmlformats.org/package/2006/content-types', 422, 'Invalid DOCX content types.');
                }
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            fclose($temporary);
        }
    }

    public function download(Request $request, string $id)
    {
        $file = DB::table('studio_uploads')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($file, 404);
        $bytes = Storage::disk('local')->get($file->private_path);
        abort_unless(hash_equals($file->sha256, hash('sha256', $bytes)), 409, 'Reference integrity check failed.');

        return response($bytes)->header('Content-Type', 'application/octet-stream')->header('Content-Disposition', "attachment; filename=reference.bin; filename*=UTF-8''".rawurlencode($file->name))->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
