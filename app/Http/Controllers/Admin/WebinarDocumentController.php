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
            // Office packages can be detected as ZIP; their extensions and contents are checked below.
            'file' => ['required', 'file', 'max:8192', 'mimes:xlsx,pdf,docx,png,jpg,jpeg,zip', 'extensions:xlsx,pdf,docx,png,jpg,jpeg'],
            'version' => [$document ? 'required' : 'sometimes', 'integer', 'min:1'],
        ], [
            'file.mimes' => 'Upload an Excel (.xlsx), PDF, DOCX, PNG or JPG file.',
        ]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowedMimes = match ($extension) {
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            default => [],
        };
        if (! in_array($detectedMime, $allowedMimes, true)) {
            throw ValidationException::withMessages(['file' => 'The file content does not match an allowed document type.']);
        }
        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $label = strtoupper($extension);
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
                throw ValidationException::withMessages(['file' => 'Upload a valid '.$label.' document.']);
            }
            try {
                $size = 0;
                $mainPart = $extension === 'xlsx' ? 'xl/workbook.xml' : 'word/document.xml';
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($mainPart) !== false && $zip->numFiles <= 2000;
                for ($i = 0; $i < $zip->numFiles && $valid; $i++) {
                    $entry = $zip->statIndex($i);
                    $size += $entry['size'];
                    $valid = $size <= 32 * 1024 * 1024 && ! preg_match('/(?:vbaProject|embeddings\/|\.\.\/)/i', $entry['name']);
                    if ($extension === 'xlsx' && $valid) {
                        $name = str_replace('\\', '/', $entry['name']);
                        $valid = ! str_contains($name, '../') && ! str_starts_with($name, '/') && ! str_contains($name, ':')
                            && ! preg_match('#(?:^|/)(?:macrosheets|activeX|externalLinks|embeddings)(?:/|$)#i', $name);
                        if ($valid && preg_match('/\.(xml|rels)$/i', $name)) {
                            $xml = $zip->getFromIndex($i);
                            $valid = $xml !== false && ! preg_match('/<!DOCTYPE|<!ENTITY/i', $xml);
                        }
                    }
                }
                if ($valid && $extension === 'xlsx') {
                    $valid = $this->isPlainWorkbook($zip);
                }
                if (! $valid) {
                    throw ValidationException::withMessages(['file' => 'This '.$label.' is invalid, too large when expanded, or contains embedded active content. Upload a plain document or PDF.']);
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

    private function isPlainWorkbook(\ZipArchive $zip): bool
    {
        $types = new \DOMDocument;
        $typesXml = $zip->getFromName('[Content_Types].xml');
        if ($typesXml === false || $typesXml === '' || ! $types->loadXML($typesXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return false;
        }
        $xpath = new \DOMXPath($types);
        $xpath->registerNamespace('t', 'http://schemas.openxmlformats.org/package/2006/content-types');
        $mainType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
        if ($xpath->query('/t:Types/t:Override[@PartName="/xl/workbook.xml" and @ContentType="'.$mainType.'"]')->length !== 1) {
            return false;
        }
        foreach ($xpath->query('/t:Types/*/@ContentType') as $type) {
            if (preg_match('/macroEnabled|vbaProject|macrosheet|activeX|oleObject/i', $type->nodeValue)) {
                return false;
            }
        }
        $workbook = new \DOMDocument;
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false || $workbookXml === '' || ! $workbook->loadXML($workbookXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return false;
        }
        $namespace = $workbook->documentElement?->namespaceURI;
        if (! in_array($namespace, ['http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'http://purl.oclc.org/ooxml/spreadsheetml/main'], true)) {
            return false;
        }
        $xpath = new \DOMXPath($workbook);
        $xpath->registerNamespace('s', $namespace);

        return $xpath->query('/s:workbook/s:sheets/s:sheet')->length > 0;
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
