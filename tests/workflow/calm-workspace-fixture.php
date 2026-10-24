<?php

use App\Models\EmailDelivery;
use App\Models\Webinar;

// Reuse the guarded synthetic setup; it refuses real databases and providers.
ob_start();
require __DIR__.'/ui-refinement-fixture.php';
$fixture = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);

$uploads = Webinar::create([
    'title' => 'A document workspace ready before the first upload',
    'slug' => 'calm-uploads-'.bin2hex(random_bytes(5)),
    'creation_mode' => 'uploads', 'created_by' => $webinar->created_by,
    'status' => 'draft', 'timezone' => 'Asia/Manila', 'data_retention_days' => 30,
]);
$subject = 'A complete delivery subject that remains readable on a narrow phone without hovering or scrolling sideways';
$detail = 'Synthetic delivery detail: review the selected address and try again after correcting the recorded problem.';
EmailDelivery::create([
    'webinar_id' => $webinar->id, 'type' => 'participant_message',
    'provider' => 'log', 'status' => 'failed', 'recipient_email' => 'calm-reader@example.test',
    'subject' => $subject, 'payload' => [], 'attempts' => 1, 'last_error' => $detail,
]);

echo json_encode($fixture + ['uploads' => $uploads->id, 'uploadsTitle' => $uploads->title,
    'subject' => $subject, 'detail' => $detail], JSON_THROW_ON_ERROR);
