#!/usr/local/bin/php -q
<?php namespace ProcessWire;
// before we do anything, copy the email to a file
// this is in case the PW API or database is not available in real time
// get the date and time as a string for the file name
$date = date('Y-m-d_H-i-s');
// make sure the required directories exist

// Main queue directory - for emails that were not processed immediately if API or database not available
if(!is_dir(__DIR__ . '/../../../site/assets/emailpipe/queue')) {
    mkdir(__DIR__ . '/../../../site/assets/emailpipe/queue', 0755, true);
}
// For emails that could not be processed because the parent page with matching 'to' address was not found
if(!is_dir(__DIR__ . '/../../../site/assets/emailpipe/unknown')) {
	mkdir(__DIR__ . '/../../../site/assets/emailpipe/unknown', 0755, true);
}
// For emails where the  sender fails checks
if(!is_dir(__DIR__ . '/../../../site/assets/emailpipe/quarantine')) {
	mkdir(__DIR__ . '/../../../site/assets/emailpipe/quarantine', 0755, true);
}

// For emails that could not be opened
if(!is_dir(__DIR__ . '/../../../site/assets/emailpipe/bad')) {
	mkdir(__DIR__ . '/../../../site/assets/emailpipe/bad', 0755, true);
}
$filename = __DIR__ . '/../../../site/assets/emailpipe/queue/' . $date . '.eml';
copy("php://stdin", $filename);

// include the ProcessWire index file for the API
include(__DIR__ . '/../../../index.php');
//wire()->log->save('emailpipe', 'emailpipe.php started');


$pipe = new PipeEmailToPage();

$pipe->processMessage($filename);


