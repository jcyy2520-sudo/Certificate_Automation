<?php

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

// Generates synthetic workbook bytes only; this script never boots Laravel.
require __DIR__.'/../../vendor/autoload.php';

$path = tempnam(sys_get_temp_dir(), 'workflow-responses-');
try {
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues([
        'Email Address', 'Full Name', 'Campus', 'Feedback', 'Year Level',
        ...array_map(fn ($i) => 'Extra question '.$i, range(1, 25)),
    ]));
    $writer->addRow(Row::fromValues([
        'adaptive.browser@example.test', 'Adaptive Browser Participant', 'North campus',
        str_repeat('Clear examples and useful discussion. ', 20).'Final answer marker.', '0',
        ...array_map(fn ($i) => 'Answer '.$i, range(1, 25)),
    ]));
    $writer->close();
    readfile($path);
} finally {
    unlink($path);
}
