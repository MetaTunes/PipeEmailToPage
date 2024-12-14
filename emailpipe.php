#!/usr/local/bin/php -q
<?php namespace ProcessWire;
include(__DIR__ . '/../../../index.php');
wire()->log->save('debug', 'emailpipe.php started');


use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Header\HeaderConsts;

$pipe = new PipeEmailToPage();
$pipeConfig = wire()->modules->getConfig('PipeEmailToPage');

// use an instance of MailMimeParser as a class dependency
$mailParser = new MailMimeParser();
// parse() accepts a string, resource or Psr7 StreamInterface
// pass `true` as the second argument to attach the passed $handle and close
// it when the returned IMessage is destroyed.
$handle = fopen("php://stdin", "r");
$message = $mailParser->parse($handle, false);         // returns `IMessage`

$from = $message->getHeader(HeaderConsts::FROM)->getEmail();
$fromName = $message->getHeader(HeaderConsts::FROM)->getPersonName();
$subject = $message->getSubject();
$text = $message->getTextContent();
$html = $message->getHtmlContent();
$to = $message->getHeader(HeaderConsts::TO)->getAddresses()[0]->getEmail();
wire()->log->save('debug', 'from: ' . $from);
wire()->log->save('debug', 'name: ' . $fromName);
wire()->log->save('debug', 'subject: ' . $subject);
wire()->log->save('debug', 'to: ' . $to);
wire()->log->save('debug', 'body: ' . $text);
wire()->log->save('debug', 'html: ' . $html);

//Check if the email is from a valid sender
if (!$pipe->checkSender($from)) {
    wire()->log->save('debug', 'Invalid sender: ' . $from);
    exit();
}

//Check if the email is to a valid recipient
$parentTemplates = implode('|', $pipeConfig['categoryTemplates']);
$emailToField = wire()->fields->get($pipeConfig['emailToField'])->name;
wire()->log->save('debug', 'emailToField: ' . $emailToField);
$parentPage = pages()->get("template=$parentTemplates, $emailToField=$to");  // will only get the first. ToDo report warning if there are more?
if (!$parentPage || $parentPage->id == 0) {
    wire()->log->save('debug', 'No parent page found for recipient: ' . $to);
    exit();
}

$mailReceivedPage = new DefaultPage();
$mailReceivedPage->of(false);

$mailReceivedPage->template = wire()->templates->get($pipeConfig['receivedTemplate']);
$emailFrom = wire()->fields->get($pipeConfig['emailFromField'])->name;
$emailSubject = wire()->fields->get($pipeConfig['emailSubjectField'])->name;
$emailBodyField = wire()->fields->get($pipeConfig['emailBodyField']);
$emailBody = $emailBodyField->name;
$body = ($emailBodyField->contentType == 1) ? $html : $text;
$emailImages = wire()->fields->get($pipeConfig['emailImagesField'])->name;
$emailFiles = wire()->fields->get($pipeConfig['emailFilesField'])->name;

$mailReceivedPage->parent = $parentPage;
$mailReceivedPage->$emailSubject = $subject;
$mailReceivedPage->$emailBody = $body;
$mailReceivedPage->$emailFrom = $from;
$mailReceivedPage->save();
// save attachments
$atts = $message->getAllAttachmentParts();
foreach ($atts as $ind => $part) {
	$filename = $part->getHeaderParameter(
		'Content-Type',
		'name',
		$part->getHeaderParameter(
			'Content-Disposition',
			'filename',
			'__unknown_file_name_' . $ind
		)
	);
    $filename = strtolower(wire()->sanitizer->fileName($filename, true));
    wire()->log->save('debug', 'attachment: ' . $filename);
	$ext = pathinfo($filename, PATHINFO_EXTENSION);
    if(in_array(strtolower($ext), ['gif', 'jpg', 'jpeg', 'png', 'peg'])) {
		$fileFieldName = $emailImages;
	} else {
		$fileFieldName = $emailFiles;
	}
    $path = wire()->config->paths->files . $mailReceivedPage->id . '/';
    wire()->log->save('debug', 'path/file: ' . $path . $filename);
	$out = fopen($path . $filename, 'w');
	$str = $part->getBinaryContentResourceHandle();
	stream_copy_to_stream($str, $out);
	fclose($str);
	fclose($out);
    $mailReceivedPage->$fileFieldName->add($path . $filename);
	$mailReceivedPage->save();
}





// close only when $message is no longer being used.
fclose($handle);
