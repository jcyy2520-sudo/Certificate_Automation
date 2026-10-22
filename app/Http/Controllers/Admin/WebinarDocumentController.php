<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarDocument;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebinarDocumentController extends Controller
{
    public function index(Webinar $webinar)
    {
        return view('admin.webinars.documents', ['webinar' => $webinar, 'documents' => $webinar->documents()->latest()->paginate(20)]);
    }

    public function store(Request $request, Webinar $webinar, AuditService $audit)
    {
        return $this->save($request, $webinar, $audit);
    }

    public function update(Request $request, Webinar $webinar, WebinarDocument $document, AuditService $audit)
    {
        return $this->save($request, $webinar, $audit, $document);
    }

    private function save(Request $request, Webinar $webinar, AuditService $audit, ?WebinarDocument $document = null)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'file' => ['required', 'file', 'max:8192', 'mimes:pdf,docx,png,jpg,jpeg', 'extensions:pdf,docx,png,jpg,jpeg'],
            'version' => [$document ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowedMimes = match ($extension) {
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            default => [],
        };
        if (! in_array($detectedMime, $allowedMimes, true)) {
            throw ValidationException::withMessages(['file' => 'The file content does not match an allowed document type.']);
        }
        if ($extension === 'docx') {
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
                throw ValidationException::withMessages(['file' => 'Upload a valid DOCX document.']);
            }
            try {
                $size = 0;
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false && $zip->numFiles <= 2000;
                for ($i = 0; $i < $zip->numFiles && $valid; $i++) {
                    $entry = $zip->statIndex($i);
                    $size += $entry['size'];
                    $valid = $size <= 32 * 1024 * 1024 && ! preg_match('/(?:vbaProject|embeddings\/|\.\.\/)/i', $entry['name']);
                }
                if (! $valid) {
                    throw ValidationException::withMessages(['file' => 'This DOCX is invalid, too large when expanded, or contains embedded active content. Upload a plain document or PDF.']);
                }
            } finally {
                $zip->close();
            }
        }
        $disk = (string) config('webinar.document_disk');
        abort_if($disk === 'public' || config('filesystems.disks.'.$disk.'.visibility') === 'public', 503, 'Private document storage is not configured.');
        $path = 'webinar-documents/'.$webinar->id.'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }
        try {
            DB::transaction(function () use ($webinar, $document, $data, $disk, $path, $extension, $file): void {
                $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
                abort_if($locked->deletion_started_at || $locked->archived_at, 409, 'This webinar is unavailable for uploads.');
                $version = 1;
                if ($document) {
                    $previous = $locked->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                    abort_unless((int) $previous->version === (int) $data['version'], 409, 'This document changed. Refresh before replacing it.');
                    $version = $previous->version + 1;
                    $previous->delete();
                }
                $locked->documents()->create([
                    'title' => $data['title'], 'storage_disk' => $disk, 'file_path' => $path,
                    'extension' => $extension, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'version' => $version,
                ]);
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
        $audit->record($request, $document ? 'webinar-document.replaced' : 'webinar-document.uploaded', $webinar);

        return redirect()->route('admin.webinars.documents.index', $webinar)->with('success', $document ? 'Document replaced. The previous file is retained privately until webinar deletion.' : 'Document uploaded.');
    }

    public function download(Webinar $webinar, WebinarDocument $document)
    {
        abort_unless($document->webinar_id === $webinar->id, 404);

        return Storage::disk($document->storage_disk)->download($document->file_path, 'document-'.$document->public_id.'.'.$document->extension, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, Webinar $webinar, WebinarDocument $document, AuditService $audit)
    {
        DB::transaction(function () use ($webinar, $document): void {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->deletion_started_at, 409);
            $locked->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail()->delete();
        });
        $audit->record($request, 'webinar-document.removed', $webinar);

        return back()->with('success', 'Document removed from this webinar. Its private file is retained until webinar deletion.');
    }
}
