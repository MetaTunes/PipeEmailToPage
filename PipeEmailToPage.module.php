<?php namespace ProcessWire;
//wire()->log->save('emailpipe', 'PipeEmailToPage.module.php started');
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Header\HeaderConsts;

class PipeEmailToPage extends Process implements Module, ConfigurableModule {

	public static function getModuleinfo() {
		return [
			'title' => 'Settings and admin page for PipeEmailToPage module',
			'summary' => 'Receive and manage emails pushed from cPanel',
			'author' => 'Mark Evens',
			'version' => 0.3,
			'permission' => 'admin-database-mailreceived',
			// page that you want created to execute this module
			'page' => [
				'name' => 'mailreceived', // page title for this admin-page
				'parent' => 'setup',    // parent name (under admin)
				'title' => 'Mail Received',
			],
			'autoload' => true,
		];
	}

	public function init() {
// To prevent lock file stopping LazyCron from running, wrap in a try catch block
// (see https://processwire.com/talk/topic/18216-lazycron-stops-firing/ - I'm not sure the issue is fixed)
		try {
			wire()->addHook('LazyCron::everyMinute', $this, 'processQueue');
		} catch(\Throwable $e) {
			wire()->log->save('emailpipe', 'Error in LazyCron: ' . $e->getMessage());
		}
	}

	public function processQueue() {
		$queueDir = wire('config')->paths->assets . 'emailpipe/queue/';
		$files = glob($queueDir . '*.eml');
		foreach($files as $file) {
			//wire()->log->save('emailpipe', 'Processing file: ' . $file);
			$this->processMessage($file);
		}
	}


	public function ___execute() {
		$out = "This is a summary table of received emails. You can sort by clicking on the various headers. <br/>";
		if($this->reportPage && $this->modules->isInstalled('ProcessPageListerPro')) {
			$mailReceivedReport = $this->pages->get($this->reportPage)->url;
			$out .= "For a more flexible report where you can use various filters, <a href='" . $mailReceivedReport . "'>click here</a>.<br/>";
		}
		$table = $this->wire('modules')->get("MarkupAdminDataTable");
		$table->headerRow(["Category", "Parent", "Title", "To", "From", "Text summary", "Images", "Files", "Created", "View text"]);
		$table->setSortable(true);
		$table->set('encodeEntities', false);

		$mailArray = wire('pages')->find("template={$this->receivedTemplate}, sort=-created, parent!=7, include=all");
		foreach($mailArray as $mail) {
			// Shorten body text
			$string = strip_tags($mail->{$this->emailBodyField});
			$bodyName = $this->fields->get($this->emailBodyField)->name;
			$addresseesName = $this->fields->get($this->addresseesField)->name;
			$body = (strlen($string) > 50) ? substr($string,0,45).'...' : $string;
			// Create table row
			$data = array(
				// Values with a string key are converter to a link: title => link
				$mail->parent()->template->name,
				$mail->parent()->title => $this->config->urls->admin . 'page/edit/?id=' . $mail->parent()->id,
				$mail->title => $this->config->urls->admin . 'page/edit/?id=' . $mail->id,
				$mail->{$this->addresseesField},
				$mail->{$this->emailFromField},
				$body,
				$mail->{$this->emailImages}->count(),
				$mail->{$this->emailFiles}->count(),
				date('Y-m-d', $mail->created),
				'<i class="fa fa-file-text"></i>' =>
					'./mail-body/?id=' . $mail->id . '&addressees=' . $addresseesName . '&body=' . $bodyName . '&modal=1',
			);
			$table->row($data);

		}
		$out .= $table->render();
		return $out;
	}

	public function ___executeMailBody() {
		$id = wire('input')->get('id');
		$mail = wire('pages')->get($id);
		$addressees = wire('input')->get('addressees');
		$body = wire('input')->get('body');
		return $mail->$addressees . '<hr/>' . $mail->$body;
	}

	/**
	 * Check if the sender is allowed (default is to allow)
	 * You can hook this method in your init.php file to return a different result
	 * In your hook, retrieve $from as $event->arguments(0)
	 * If you hook before this method set $event->arguments(1) to true or false, the white and black lists will be applied afterwards
	 * If you hook after this method, you can ignore the white and black lists and apply you own logic
	 * and set $event->return to false if the sender is not valid
	 *
	 * @param string $from
	 * @return bool
	 */
	public function ___checkSender($from, $default = true) {
		$pipeConfig = wire()->modules->getConfig($this->className);
		$from = strtolower($from);
		$fromDomain = explode('@', $from)[1];
		$blackList = explode("\n", strtolower($pipeConfig['blackList']));
		$whiteList = explode("\n", strtolower($pipeConfig['whiteList']));
		wire()->log->save('emailpipe', 'from: ' . $from . ' fromDomain: ' . $fromDomain . ' blackList: ' . implode(', ', $blackList) . ' whiteList: ' . implode(', ', $whiteList));
		if(in_array($from, $blackList) || in_array($fromDomain, $blackList)) return false;
		if(in_array($from, $whiteList) || in_array($fromDomain, $whiteList)) return true;
		return $default;
	}

	/**
	 * Process the email message
	 *
	 * @param string $filename
	 */
	public function processMessage($filename) {
		wire()->log->save('emailpipe', 'Processing file: ' . $filename);
		// use an instance of MailMimeParser as a class dependency
		$mailParser = new MailMimeParser();
// parse() accepts a string, resource or Psr7 StreamInterface
// pass `true` as the second argument to attach the passed $handle and close it when the returned IMessage is destroyed.
		try {
			$handle = fopen($filename, "r");
			$message = $mailParser->parse($handle, false);         // returns `IMessage` instance

			$from = $message->getHeader(HeaderConsts::FROM)->getEmail();
			$fromName = $message->getHeader(HeaderConsts::FROM)->getPersonName();
			$subject = $message->getSubject();
			$text = $message->getTextContent();
			$html = $message->getHtmlContent();
			$to = $message->getHeader(HeaderConsts::TO)->getAddresses(); // returns an array of `IAddress` instances
			$cc = ($message->getHeader(HeaderConsts::CC)) ? $message->getHeader(HeaderConsts::CC)->getAddresses() : null;
			$bcc = ($message->getHeader(HeaderConsts::BCC)) ? $message->getHeader(HeaderConsts::BCC)->getAddresses() : null;
			$toString = implode(', ', $to);
			$ccString = ($cc) ? '; cc: ' . implode(', ', $cc) : '';
			$bccString = ($bcc) ? '; bcc: ' . implode(', ', $bcc) : '';
			$addressString = 'To: ' . $toString . $ccString . $bccString;
//			wire()->log->save('emailpipe', 'toString: ' . $toString);

//		wire()->log->save('emailpipe', 'from: ' . $from);
//		wire()->log->save('emailpipe', 'name: ' . $fromName);
//		wire()->log->save('emailpipe', 'subject: ' . $subject);
//		wire()->log->save('emailpipe', 'to: ' . $to);
//		wire()->log->save('emailpipe', 'body: ' . $text);
//		wire()->log->save('emailpipe', 'html: ' . $html);

//Check if the email is from a valid sender
			if(!$this->checkSender($from)) {
				wire()->log->save('emailpipe', 'Invalid sender: ' . $from . '. Moving file to "quarantine" subdirectory');
				$quarantineFilename = str_replace('queue', 'quarantine', $filename);
				rename($filename, $quarantineFilename);
				return;
			}
//			wire()->log->save('emailpipe', 'Valid sender: ' . $from);
			$pipeConfig = wire()->modules->getConfig($this->className); // NB Not clear why this is needed, but using $this->emailToField etc. does not seem to work when called from the emailpipe.php script
//Check if the email is to a valid recipient
			$parentTemplates = implode('|', $pipeConfig['categoryTemplates']);
			if(!$parentTemplates) {
				wire()->log->save('emailpipe', 'No parent templates selected. Moving file to "unknown" subdirectory');
				wire()->error('PipeEmailToPage: No parent templates selected. Moving file to "unknown" subdirectory');
				$unknownFilename = str_replace('queue', 'unknown', $filename);
				rename($filename, $unknownFilename);
				return;
			}
//			wire()->log->save('emailpipe', 'parentTemplates: ' . $parentTemplates);
			$emailToField = (wire()->fields->get($pipeConfig['emailToField'])) ? wire()->fields->get($pipeConfig['emailToField'])->name : null;
			if(!$emailToField) {
				wire()->log->save('emailpipe', 'No email to field selected. Moving file to "unknown" subdirectory');
				wire()->error('PipeEmailToPage: No email to field selected. Moving file to "unknown" subdirectory');
				$unknownFilename = str_replace('queue', 'unknown', $filename);
				rename($filename, $unknownFilename);
				return;
			}
//			wire()->log->save('emailpipe', 'emailToField: ' . $emailToField);
			$foundRecipient = null;
			$parentPage = null;
			foreach($to as $address) {
				$toEmail = $address->getEmail();
				$parentPage = pages()->get("template=$parentTemplates, $emailToField=$toEmail");  // will only get the first. ToDo report warning if there are more?
				if($parentPage && $parentPage->id) {
					$foundRecipient = 'to';
					break;
				}
			}
			if(!$foundRecipient) {
				foreach($cc as $address) {
					$toEmail = $address->getEmail();
					$parentPage = pages()->get("template=$parentTemplates, $emailToField=$toEmail");  // will only get the first. ToDo report warning if there are more?
					if($parentPage && $parentPage->id) {
						$foundRecipient = 'cc';
						break;
					}
				}
			}
			if(!$foundRecipient) {
				foreach($bcc as $address) {
					$toEmail = $address->getEmail();
					$parentPage = pages()->get("template=$parentTemplates, $emailToField=$toEmail");  // will only get the first. ToDo report warning if there are more?
					if($parentPage && $parentPage->id) {
						$foundRecipient = 'bcc';
						break;
					}
				}
			}
			if(!$foundRecipient || !$parentPage) {
				wire()->log->save('emailpipe', 'No parent page found for any recipient in: ' . $addressString . '. Moving file to "unknown" subdirectory');
				$unknownFilename = str_replace('queue', 'unknown', $filename);
				rename($filename, $unknownFilename);
				return;
			}

			$mailReceivedPage = new DefaultPage();
			$mailReceivedPage->of(false);
			if(!wire()->templates->get($pipeConfig['receivedTemplate'])) {
				wire()->log->save('emailpipe', 'No received template selected. Moving file to "unknown" subdirectory');
				wire()->error('PipeEmailToPage: No received template selected. Moving file to "unknown" subdirectory');
				$unknownFilename = str_replace('queue', 'unknown', $filename);
				rename($filename, $unknownFilename);
				return;
			}
			$mailReceivedPage->template = wire()->templates->get($pipeConfig['receivedTemplate']);
			$emailFrom = (wire()->fields->get($pipeConfig['emailFromField'])) ? wire()->fields->get($pipeConfig['emailFromField'])->name : '';
			$addressees = (wire()->fields->get($pipeConfig['addresseesField'])) ? wire()->fields->get($pipeConfig['addresseesField'])->name : '';
			$emailSubject = (wire()->fields->get($pipeConfig['emailSubjectField'])) ? wire()->fields->get($pipeConfig['emailSubjectField'])->name : '';
			$emailBodyField = wire()->fields->get($pipeConfig['emailBodyField']);
			$emailBody = ($emailBodyField) ? $emailBodyField->name : '';
			$body = ($emailBodyField && isset($emailBodyField->contentType) && $emailBodyField->contentType == 1) ? $html : $text;
			$emailImages = (wire()->fields->get($pipeConfig['emailImagesField'])) ? wire()->fields->get($pipeConfig['emailImagesField'])->name : '';
			$emailFiles = (wire()->fields->get($pipeConfig['emailFilesField'])) ? wire()->fields->get($pipeConfig['emailFilesField'])->name : '';

			$mailReceivedPage->parent = $parentPage;
			if($addressees) $mailReceivedPage->$addressees = $addressString;
			if($emailSubject) $mailReceivedPage->$emailSubject = $subject;
			if($emailBody) $mailReceivedPage->$emailBody = $body;
			if($emailFrom) $mailReceivedPage->$emailFrom = $from;
			$mailReceivedPage->save();
// save attachments
			$atts = $message->getAllAttachmentParts();
			foreach($atts as $ind => $part) {
				$attachFilename = $part->getHeaderParameter(
					'Content-Type',
					'name',
					$part->getHeaderParameter(
						'Content-Disposition',
						'filename',
						'__unknown_file_name_' . $ind
					)
				);
				$attachFilename = strtolower(wire()->sanitizer->fileName($attachFilename, true));
//				wire()->log->save('emailpipe', 'attachment: ' . $attachFilename);
				$ext = pathinfo($attachFilename, PATHINFO_EXTENSION);
				if(in_array(strtolower($ext), ['gif', 'jpg', 'jpeg', 'png', 'peg'])) {
					$fileFieldName = $emailImages;
				} else {
					$fileFieldName = $emailFiles;
				}
				if(!$fileFieldName) {
					wire()->log->save('emailpipe', 'No field selected for attachments. Unable to save attachments. Moving file to "unknown" subdirectory');
					wire()->error('PipeEmailToPage: No field selected for attachments. Unable to save attachments. Moving file to "unknown" subdirectory');
					$unknownFilename = str_replace('queue', 'unknown', $filename);
					rename($filename, $unknownFilename);
					return;
				}
				$path = wire()->config->paths->files . $mailReceivedPage->id . '/';
//				wire()->log->save('emailpipe', 'path/file: ' . $path . $attachFilename);
				$out = fopen($path . $attachFilename, 'w');
				$str = $part->getBinaryContentResourceHandle();
				stream_copy_to_stream($str, $out);
				fclose($str);
				fclose($out);
				$mailReceivedPage->$fileFieldName->add($path . $attachFilename);
				$mailReceivedPage->save();
			}
			// close only when $message is no longer being used.
			fclose($handle);
//			wire()->log->save('emailpipe', 'Remove file: ' . realpath($filename));
			wire()->files->unlink(realpath($filename), true);

		} catch(\Throwable $e) {
			wire()->log->save('emailpipe', 'Could not process file: ' . $filename . '. This could be because it is a bad file or because of a code error. File has been moved to "bad" subdirectory');
			$badFilename = str_replace('queue', 'bad', $filename);
			rename($filename, $badFilename); // keeps file but stops it from being re-processed
			return;
		}

	}

	/**
	 * Get module configuration inputs
	 *
	 * As an alternative, configuration can also be specified in an external file
	 * with a PHP array. See an example in the /extras/ProcessHello.config.php file.
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {

		// Parents
		/** @var InputfieldFieldset $parents */
		$parents = $this->wire(new InputfieldFieldset());
		$parents->label = $this->_('Parents for Received Mail pages');
		$parents->columnWidth = 100;
		$inputfields->add($parents);

		/**
		 * Configuration field for category templates
		 * One or more templates can be selected
		 */
		$f = $this->modules->get('InputfieldAsmSelect');
		$f_name = 'categoryTemplates';
		$f->name = $f_name;
		$f->label = 'Email Category Templates';
		$f->description = 'Select one or more templates to use for the parents of the received emails';
		$f->notes = 'Submit changes to see selectable fields';
		$f->columnWidth = 50;
		$f->addOptions($this->templates->find('')->explode('name', ['key' => 'id']));
		$f->value = $this->$f_name;
		$parents->add($f);
		/**
		 * Configuration field for email field
		 * The field to use for the email address that the email is sent to
		 * This should be a field that is in all of the selected templates and should be of type 'email'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailToField';
		$f->name = $f_name;
		$f->label = 'Email To Field';
		$f->description = 'Select the field to use for the email address';
		$f->notes = 'This should be a field that is in all of the selected templates and should be of type \'email\'';
		$f->columnWidth = 50;
		if($this->categoryTemplates) {
			foreach($this->categoryTemplates as $templateId) {
				$template = $this->templates->get($templateId);
				foreach($this->fields->find("type=FieldtypeEmail") as $field) {
					if($template && $template->fields->has($field)) {
						$f->addOption($field->id, $field->name);
					}
				}
			}
		}
		$f->value = $this->$f_name;
		$parents->add($f);

		// Received mails
		/** @var InputfieldFieldset $mails */
		$mails = $this->wire(new InputfieldFieldset());
		$mails->label = $this->_('Received Mail pages');
		$mails->columnWidth = 100;
		$inputfields->add($mails);
		/**
		 * Configuration field for mail received template
		 * Only one template can be selected
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'receivedTemplate';
		$f->name = $f_name;
		$f->label = 'Received email template';
		$f->description = 'Select the template to use for the received emails';
		$f->notes = 'Submit changes to see selectable fields';
		$f->columnWidth = 100;
		$f->addOptions($this->templates->find('')->explode('name', ['key' => 'id']));
		$f->value = $this->$f_name;
		$mails->add($f);

		/**
		 * Configuration field for email field
		 * The field to use for the sending email address
		 * This should be a field of type 'email'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailFromField';
		$f->name = $f_name;
		$f->label = 'Email From Field';
		$f->description = 'Select the field to use for the email address that the email was sent from';
		$f->notes = 'This should be a field that is in the selected template and should be of type \'email\'';
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeEmail") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $this->$f_name;
		$mails->add($f);

		/**
		 * Configuration field for addressees field
		 * The field to use for the receiving email addresses
		 * This should be a text field
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'addresseesField';
		$f->name = $f_name;
		$f->label = 'Addressees Field';
		$f->description = 'Select the field to use for the addresses that the email was sent to';
		$f->notes = "This should be a field that is in the selected template and should be a text or textarea field. \n
		The addresses will be stored as a string with each address separated by a comma and annotated with 'to:', 'cc:' or 'bcc:'";
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeText|FieldtypeTextarea") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $this->$f_name;
		$mails->add($f);

		/**
		 * Configuration field for title field
		 * The field to use for the email subject
		 * This should be a field of type 'PageTitle'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailSubjectField';
		$f->name = $f_name;
		$f->label = 'Email Subject Field';
		$f->description = 'Select the field to use for the email subject';
		$f->notes = 'This should be a field that is in the selected template and should be of type \'PageTitle\'';
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypePageTitle") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $this->$f_name;
		$mails->add($f);

		/**
		 * Configuration field for body field
		 * The field to use for the email body
		 * This should be a field of type 'Textarea'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailBodyField';
		$f->name = $f_name;
		$f->label = 'Email Body Field';
		$f->description = 'Select the field to use for the email body';
		$f->notes = 'This should be a field that is in the selected template and should be of type \'Textarea\'';
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeTextarea") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $this->$f_name;
		$mails->add($f);

		/**
		 * Configuration field for images field
		 * The field to use for the email image attachments
		 * This should be a field of type 'Images'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailImagesField';
		$f->name = $f_name;
		$f->label = 'Email Images Field';
		$f->description = 'Select the field to use for the email image attachments';
		$f->notes = 'This should be a field that is in the selected template and should be of type \'Images\'';
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeImage") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $this->$f_name;
		$mails->add($f);

		/**
		 * Configuration field for files field
		 * The field to use for the email file attachments
		 * This should be a field of type 'Files'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailFilesField';
		$f->name = $f_name;
		$f->label = 'Email Files Field';
		$f->description = 'Select the field to use for the email file attachments';
		$f->notes = 'This should be a field that is in the selected template and should be of type \'Files\'';
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeFile") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $this->$f_name;
		$mails->add($f);

		//Black and white lists
		/** @var InputfieldFieldset $lists */
		$lists = $this->wire(new InputfieldFieldset());
		$lists->label = $this->_('Black and White Lists');
		$lists->description = 'Whitelist will override blacklist';
		$lists->notes = 'You can modify the operation of these lists by hooking the checkSender method. See documentation.';
		$lists->columnWidth = 100;
		$inputfields->add($lists);

		/**
		 * Configuration field for black list
		 * One or more email addresses can be added to the black list
		 */
		$f = $this->modules->get('InputfieldTextarea');
		$f_name = 'blackList';
		$f->name = $f_name;
		$f->label = 'Black List';
		$f->description = 'Enter one or more email addresses / domains to block';
		$f->notes = 'One email address or domain name per line';
		$f->columnWidth = 50;
		$f->value = $this->$f_name;
		$lists->add($f);


		/**
		 * Configuration field for white list
		 * One or more email addresses can be added to the white list
		 */
		$f = $this->modules->get('InputfieldTextarea');
		$f_name = 'whiteList';
		$f->name = $f_name;
		$f->label = 'White List';
		$f->description = 'Enter one or more email addresses / domains to allow';
		$f->notes = 'One email address or domain name per line';
		$f->columnWidth = 50;
		$f->value = $this->$f_name;
		$lists->add($f);

		// Reporting
		if($this->modules->isInstalled('ProcessPageListerPro')) {
			// Reports
			/** @var InputfieldFieldset $reporting */
			$reporting = $this->wire(new InputfieldFieldset());
			$reporting->label = $this->_('Reporting');
			$reporting->columnWidth = 100;
			$inputfields->add($reporting);

			/**
			 * Configuration field for ListerPro report page (if any)
			 */
			$f = $this->modules->get('InputfieldSelect');
			$f_name = 'reportPage';
			$f->name = $f_name;
			$f->label = 'Report page';
			$f->description = 'Assuming ListerPro is installed, select the lister (if any) to use for the report';
			$f->notes = 'There will also be a summary admin page at.';
			$f->columnWidth = 100;
			foreach($this->pages->find("template=admin, process=ProcessPageListerPro") as $lister) {
				$f->addOption($lister->id, $lister->title);
			}
			$f->value = $this->$f_name;
			$reporting->add($f);
		}

	}


}