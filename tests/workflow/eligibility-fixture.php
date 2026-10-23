<?php

use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\ParticipantStatusLinkService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.url')
    || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || config('webinar.email.provider') !== 'log' || config('webinar.email.certificate_provider') !== 'log') {
    throw new RuntimeException('Eligibility fixtures require the isolated browser database and log transport.');
}
$owner = User::query()->where('email', 'workflow@example.test')->sole();
$webinar = Webinar::create(['title' => 'Eligibility workflow fixture', 'slug' => 'eligibility-'.bin2hex(random_bytes(6)), 'created_by' => $owner->id,
    'status' => 'completed', 'ends_at' => now()->subDay(), 'data_retention_days' => 30, 'timezone' => 'Asia/Manila']);
$forms = [];
foreach (['registration', 'pretest', 'posttest', 'evaluation'] as $type) {
    $forms[$type] = $webinar->forms()->create(['type' => $type, 'title' => ucfirst($type), 'status' => 'draft']);
}
foreach (['registration', 'pretest', 'posttest'] as $type) {
    $webinar->eligibilityRules()->create(['requirement' => $type, 'is_required' => true, 'minimum_score' => $type === 'posttest' ? 7 : null]);
}
$people = [];
foreach (['pending', 'eligible', 'final'] as $kind) {
    $p = $people[$kind] = $webinar->participants()->create(['full_name' => ucfirst($kind).' Eligibility Person', 'email' => 'eligibility.'.$kind.'@example.test', 'verified_at' => now()->subDay()]);
    foreach ($kind === 'pending' ? ['pretest'] : ['pretest', 'posttest'] as $type) {
        Submission::create(['participant_id' => $p->id, 'form_id' => $forms[$type]->id, 'status' => 'submitted', 'submitted_at' => now()->subDay(), 'score' => $kind === 'final' && $type === 'posttest' ? 3 : 8, 'maximum_score' => 10]);
    }
}
$import = Import::create(['webinar_id' => $webinar->id, 'form_id' => $forms['posttest']->id, 'file_size' => 100, 'original_filename' => 'synthetic.csv',
    'file_hash' => hash('sha256', bin2hex(random_bytes(16))), 'imported_by' => $owner->id, 'status' => Import::STATUS_COMMITTED, 'committed_at' => now(), 'column_map' => ['headers' => []]]);
$issue = $import->issues()->create(['webinar_id' => $webinar->id, 'row_number' => 2, 'issue_type' => ImportIssue::TYPE_UNMATCHED_EMAIL,
    'normalized_email' => $people['pending']->email, 'issue_message' => 'Synthetic held submission', 'raw_data' => ['_resolved' => ['name' => $people['pending']->full_name,
        'score' => 8, 'maximum_score' => 10, 'submitted_at' => now()->subDay()->toIso8601String(), 'options' => []]]]);
$webinar->certificateTemplates()->create(['name' => 'Eligibility fixture template', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/workflow-browser.png',
    'is_active' => true, 'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554']]);
$personalUrl = app(ParticipantStatusLinkService::class)->createLink($webinar, $people['pending'], now()->addDay());
echo json_encode(['webinar' => $webinar->id, 'pending' => $people['pending']->id, 'issue' => $issue->id, 'personalUrl' => $personalUrl, 'formToken' => $forms['registration']->public_token]);
