#!/usr/local/bin/php -q
<?php namespace ProcessWire;

// Note that this script is not called directly from the pipe, but via wrapper.sh

// before we do anything, copy the email to a file
// this is in case the PW API or database is not available in real time

// get the date and time as a string for the file name
$date = date_format(date_create(), 'Y-m-d_H-i-s-v');

///// Make sure the required directories exist   ////

// Main queue directory - for emails that were not processed immediately if API or database not available
if(!is_dir(__DIR__ . '/../../assets/emailpipe/queue')) {
    mkdir(__DIR__ . '/../../assets/emailpipe/queue', 0755, true);
}
// For emails that could not be processed because the parent page with matching 'to' address was not found
if(!is_dir(__DIR__ . '/../../assets/emailpipe/unknown')) {
 mkdir(__DIR__ . '/../../assets/emailpipe/unknown', 0755, true);
}
// For emails where the  sender fails checks
if(!is_dir(__DIR__ . '/../../assets/emailpipe/quarantine')) {
 mkdir(__DIR__ . '/../../assets/emailpipe/quarantine', 0755, true);
}
// For emails that could not be opened
if(!is_dir(__DIR__ . '/../../assets/emailpipe/bad')) {
 mkdir(__DIR__ . '/../../assets/emailpipe/bad', 0755, true);
}
// For emails that have been processed successfully
if(!is_dir(__DIR__ . '/../../assets/emailpipe/processed')) {
 mkdir(__DIR__ . '/../../assets/emailpipe/processed', 0755, true);
}

$filename = __DIR__ . '/../../assets/emailpipe/queue/' . $date . '.eml';
copy("php://stdin", $filename);

// Get the recipient & sender addresses from the command-line argument
$recipient = isset($argv[1]) ? $argv[1] : 'none';
$sender = isset($argv[2]) ? $argv[2] : 'none';

// Debugging statements
file_put_contents(__DIR__ . '/../../assets/emailpipe/debug.log', "Recipient: $recipient\nSender: $sender\n", FILE_APPEND);

$content = file_get_contents($filename);
$header = "X-Recipient-Address: " . $recipient . "\n";
$header .= "X-Sender-Address: " . $sender . "\n";
$headerEndPos = strpos($content, "\n\n");
if ($headerEndPos !== false) {
 $content = substr_replace($content, $header, $headerEndPos + 1, 0);
 file_put_contents($filename, $content);
}

// include the ProcessWire index file for the API
include(__DIR__ . '/../../../index.php');

// Debugging statement
wire()->log->save('emailpipe', 'emailpipe.php started');
wire()->log->save('emailpipe', 'Sender: ' . $sender . ' Recipient: ' . $recipient);

$pipe = new PipeEmailToPage();

$pipe->processMessage($filename);