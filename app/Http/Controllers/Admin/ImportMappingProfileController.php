<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\ImportMappingProfile;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\ImportMappingProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ImportMappingProfileController extends Controller
{
    public function __construct(private ImportMappingProfileService $profiles, private AuditService $audit) {}

    public function store(Request $request, Webinar $webinar, Import $import): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:'.ImportMappingProfileService::MAX_NAME_LENGTH]]);
        try {
            $profile = $this->profiles->save($import, $request->user(), $data['name']);
        } catch (RuntimeException $exception) {
            return $this->failure($webinar, $import, $exception, 'review');
        }
        $this->audit->record($request, 'import_mapping_profile.saved', $profile, ['import_id' => $import->id]);

        return $this->mapping($webinar, $import, 'review')->with('success', 'Column mapping saved for your future uploads of this form type.');
    }

    public function apply(Request $request, Webinar $webinar, Import $import, ImportMappingProfile $profile): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id, 404);
        try {
            $this->profiles->apply($import, $request->user(), $profile);
        } catch (RuntimeException $exception) {
            return $this->failure($webinar, $import, $exception);
        }
        $this->audit->record($request, 'import_mapping_profile.applied', $profile, ['import_id' => $import->id]);

        return $this->mapping($webinar, $import)->with('success', 'Saved columns applied. Check the destination, confirm this source and run a new dry check.');
    }

    public function destroy(Request $request, Webinar $webinar, Import $import, ImportMappingProfile $profile): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id, 404);
        try {
            $this->profiles->remove($import, $request->user(), $profile);
        } catch (RuntimeException $exception) {
            return $this->failure($webinar, $import, $exception);
        }
        $this->audit->record($request, 'import_mapping_profile.removed', $profile, ['import_id' => $import->id]);

        return $this->mapping($webinar, $import)->with('success', 'Saved column mapping removed.');
    }

    private function mapping(Webinar $webinar, Import $import, string $step = 'columns'): RedirectResponse
    {
        return redirect()->route('admin.webinars.imports.map', [$webinar, $import, 'step' => $step]);
    }

    private function failure(Webinar $webinar, Import $import, RuntimeException $exception, string $step = 'columns'): RedirectResponse
    {
        if ($exception instanceof ModelNotFoundException || $exception instanceof HttpExceptionInterface) {
            throw $exception;
        }

        return $this->mapping($webinar, $import, $step)->with('error', $exception->getMessage());
    }
}
