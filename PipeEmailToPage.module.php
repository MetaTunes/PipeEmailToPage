<?php namespace ProcessWire;
wire()->log->save('debug', 'PipeEmailToPage.module.php started');
class PipeEmailToPage extends Process implements Module, ConfigurableModule {

	public static function getModuleinfo() {
		return [
			'title' => 'Settings and admin page for PipeEmailToPage module',
			'summary' => 'Receive and manage emails pushed from cPanel',
			'author' => 'Mark Evens',
			'version' => 0.1,
			'permission' => 'admin-database-mailreceived',
			// page that you want created to execute this module
			'page' => [
				// your page will be online at /processwire/{admin}/
				'name' => 'mailreceived', // page title for this admin-page
				'parent' => 'setup',    // parent name (under admin)
				'title' => 'Mail Received',
			],
		];
	}


	public function ___execute() {
		$out = '';
		$currentUser = wire('user');
		$table = $this->wire('modules')->get("MarkupAdminDataTable");
		$table->headerRow(["Category", "Parent", "Title", "To", "From", "Text", "Images", "Files", "Created", "Spam?"]);
		$table->setSortable(true);
		$table->set('encodeEntities', false);

//        $url = './add-membership-form2';
//        $label = 'New membership';
//        $table->action(array($label => $url));
		$mailArray = wire('pages')->find("template={$this->receivedTemplate}, sort=-created, include=all");
		foreach ($mailArray as $mail) {
			// Shorten body text
			 $body = $this->shortenHtml($mail->{$this->emailBody}, 60, 30);
			// Create table row
			$data = array(
				// Values with a string key are converter to a link: title => link
				$mail->parent()->template->name,
				$mail->parent()->title => $this->config->urls->admin . 'page/edit/?id=' . $mail->parent()->id,
				$mail->title => $this->config->urls->admin . 'page/edit/?id=' . $mail->id,
				$mail->parent()->{$this->emailToField},
				$mail->{$this->emailFromField},
				$body,
				$mail->{$this->emailImages}->count(),
				$mail->{$this->emailFiles}->count(),
				date('Y-m-d', $mail->created),
				'X' => './spam-mail/?id=' . $mail->id
			);
			$table->row($data);

		}
		$out .= $table->render();
		return $out;
	}

function shortenHtml($html, $startLength, $endLength) {
    // Load the HTML into a DOMDocument
    $dom = new \DOMDocument();
    @$dom->loadHTML($html);

    // Get the plain text
    $plainText = strip_tags($html);

    // Get the beginning part
    $start = substr($plainText, 0, $startLength);
    $start = preg_replace('/\s+?(\S+)?$/', '', $start);

    // Get the end part
    $end = substr($plainText, -$endLength);
    $end = preg_replace('/^\S+/', '', $end);

    // Combine the parts with ellipsis in between
    $shortenedText = $start . '...' . $end;

    // Reintroduce HTML tags
    $startHtml = $this->reintroduceHtml($dom, $start);
    $endHtml = $this->reintroduceHtml($dom, $end);

    return $startHtml . '...' . $endHtml;
}

public function reintroduceHtml($dom, $text) {
    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//text()[contains(., "' . $text . '")]');

    foreach ($nodes as $node) {
        $parent = $node->parentNode;
        $newNode = $dom->createTextNode($text);
        $parent->replaceChild($newNode, $node);
        return $dom->saveHTML($parent);
    }

    return $text;
}



	public function ___executeSpamMail() {
	}

	/**
	 * Check if the sender is a valid member
	 * You need to hook this method in your init.php file to return any result other than true
	 * Otherwise all senders will be accepted
	 * In your hook, retrieve $from as $event->arguments(0) and set $event->return to false if the sender is not valid
	 *
	 * @param string $from
	 * @return bool
	 */
	public function ___checkSender($from) {
 		return true;
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
		/** @var InputfieldFieldset $dbName */
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
		/** @var InputfieldFieldset $dbName */
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
		$f->columnWidth = 50;
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

	}




}