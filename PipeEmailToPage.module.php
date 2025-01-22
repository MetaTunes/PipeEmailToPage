<?php namespace ProcessWire;

// MailMimeParser is a PHP library for parsing and working with emails
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Header\HeaderConsts;

// SPFCheck is a PHP library for checking SPF records
use Mika56\SPFCheck\DNS\DNSRecordGetter;
use Mika56\SPFCheck\SPFCheck;

// DKIMValidator is a PHP library for validating DKIM signatures
use PHPMailer\DKIMValidator\Validator;
use PHPMailer\DKIMValidator\DKIMException;

// HTMLPurifier is a PHP library for purifying HTML in email bodies so they can be safely stored and displayed
use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_AttrTransform_TargetBlank;

class PipeEmailToPage extends Process implements Module, ConfigurableModule {


	public static function getModuleinfo(): array {
		return [
			'title' => 'PipeEmailToPage',
			'summary' => 'Receive and manage emails pushed from cPanel',
			'author' => 'Mark Evens',
			'version' => "1.0.4",
			'permission' => 'admin-database-mailreceived',
			// page that you want created to execute this module
			'page' => [
				'name' => 'mailreceived', // page title for this admin-page
				'parent' => 'setup',    // parent name (under admin)
				'title' => 'Mail Received',
			],
			'requires' => 'PHP>=8.1', 'ProcessWire>=3.0.239',
			'autoload' => true,
		];
	}

	public function init(): void {
		require __DIR__ . '/vendor/autoload.php'; // include the MailMimeParser library etc.

		$this->wire()->config->scripts->add($this->wire()->urls->siteModules . 'PipeEmailToPage/PipeEmailToPage.js');
		$this->wire()->config->styles->add($this->wire()->urls->siteModules . 'PipeEmailToPage/PipeEmailToPage.css');
// To prevent lock file stopping LazyCron from running, wrap in a try catch block
// (see https://processwire.com/talk/topic/18216-lazycron-stops-firing/ - I'm not sure the issue is fixed)
		try {
			wire()->addHook('LazyCron::everyMinute', $this, 'processQueue');
		} catch(\Throwable $e) {
			wire()->log->save('emailpipe', sprintf($this->_('Error in LazyCron processQueue: %s'), $e->getMessage()));
		}
		try {
			wire()->addHook('LazyCron::everyDay', $this, 'cleanUp');
		} catch(\Throwable $e) {
			wire()->log->save('emailpipe', sprintf($this->_('Error in LazyCron cleanUp: %s'), $e->getMessage()));
		}
		wire()->addHookAfter('Pages::saved', $this, 'processFails');
		wire()->addHookAfter('Modules::saveConfig', $this, 'processFails');

	}

	public function ready(): void {
		// not currently used
	}

	/**
	 * Processes all email files in the queue directory.
	 * Called by LazyCron
	 *
	 * This method scans the `queue` directory for email files with the `.eml` extension.
	 * For each file found, it calls the `processMessage` method to handle the email processing.
	 *
	 */
	public function processQueue(): void {
		$queueDir = wire('config')->paths->assets . 'emailpipe/queue/';
		$files = glob($queueDir . '*.eml');
		foreach($files as $file) {
			//wire()->log->save('emailpipe', 'Processing file: ' . $file);
			$this->processMessage($file);
		}
	}

	/**
	 * Cleans up old email files in specified subdirectories.
	 * Called by LazyCron
	 *
	 * This method iterates through the specified subdirectories (`quarantine`, `unknown`, `bad`, `processed`)
	 * and deletes email files (`.eml`) that are older than the retention period defined for each subdirectory.
	 */
	public function cleanUp(): void {
		// Define subdirectories and their corresponding retention period fields
		$subDirNames = ['quarantine' => 'retentionQuarantine', 'unknown' => 'retentionUnknown', 'bad' => 'retentionBad', 'processed' => 'retentionProcessed', 'orphans' => 'retentionOrphans'];

		// Iterate through each subdirectory
		foreach($subDirNames as $subDirName => $retentionField) {
			$tabName = $subDirName;
			if($tabName == 'orphans') $subDirName = 'processed';
			// Get all .eml files in the current subdirectory
			$files = glob(wire('config')->paths->assets . 'emailpipe' . $subDirName . '/' . '*.eml');
			// For orphans, skip files with a corresponding page
			if($tabName == 'orphans') {
				foreach($this->pages()->find("template={$this->receivedTemplate}, parent!=7") as $page) {
					$filename = $page->meta('filename');
					$key = array_search($filename, $files);
					if($key !== false) {
						unset($files[$key]);
					}
				}
			}

			// Iterate through each file in the subdirectory
			foreach($files as $file) {
				// Check if the file is older than the retention period
				if(filemtime($file) < time() - 60 * 60 * 24 * (int)$this->$retentionField) {
					// Log the deletion of the file
					wire()->log->save('emailpipe', sprintf($this->_('Deleting file: %s'), $file));
					// Delete the file
					unlink($file);
				}
			}
		}
	}

	/**
	 * Processes failed email files and moves them back to the queue.
	 * Called by the `Pages::saved` and `Modules::saveConfig` hooks.
	 *
	 * It checks if the saved page or module configuration is relevant to the email processing.
	 * If so, it moves email files from the `quarantine`, `unknown`, and `bad` subdirectories
	 * back to the `queue` directory for reprocessing.
	 *
	 * @param HookEvent $event The event object containing information about the hook.
	 */
	public function processFails($event): void {
		// Get the array of category template IDs from the module configuration
		if(!isset(wire('modules')->getConfig($this->className)['categoryTemplates'])) return;
		$templateArray = wire('modules')->getConfig($this->className)['categoryTemplates'];
		// Get the template of the saved page, if available
		$template = $event->arguments(0)->template ?? null;
		// Check if the saved page is relevant to the email processing
		if($event->arguments(0) == $this->className || ($template && in_array($template->id, $templateArray))) {
			// Define the subdirectories to check for failed email files
			$subDirNames = ['quarantine', 'unknown', 'bad'];
			// Iterate through each subdirectory
			foreach($subDirNames as $subDirName) {
				$this->reprocessDirectory($subDirName);
			}
		}
	}
#

	/**
	 * Reprocesses all email files in the specified subdirectory.
	 *
	 * @param $subDirName
	 * @return void
	 */
	protected function reprocessDirectory($subDirName): void {
		// Get the path to the current subdirectory
		$subDir = wire('config')->paths->assets . 'emailpipe/' . $subDirName . '/';
		// Get an array of all .eml files in the current subdirectory
		$files = glob($subDir . '*.eml');
		// Iterate through each file in the subdirectory
		foreach($files as $filename) {
			$this->reprocessFile($filename, $subDirName);
		}
	}

	/**
	 * Put the file in the queue directory and process it.
	 *
	 * @param $filename
	 * @param $subDirName
	 * @return void
	 */
	protected function reprocessFile($filename, $subDirName): void {
		// Define the new path for the file in the queue directory
		$queueFilename = str_replace($subDirName, 'queue', $filename);
		// Move the file back to the queue directory
		rename($filename, $queueFilename);
		//bd($queueFilename, 'queueFilename');
		$this->processMessage($queueFilename);
	}


	/**
	 * Renders the form with tabs for processed, unknown, quarantined & bad emails.
	 *
	 * This method initializes the form and adds tabs for processed emails, emails with unknown recipients,
	 *  quarantined and bad emails. It uses the `MailMimeParser` library to parse email files.
	 *
	 * @return string The rendered form.
	 */
	public function ___execute(): string {
		$this->modules->get('JqueryWireTabs');
		$mailParser = new MailMimeParser();

		/* @var $form InputfieldForm */
		$form = $this->modules->get("InputfieldForm");
		$form->attr('id', 'PipeEmailToPage');

		$this->addProcessedTab($form);

		$footers = [];
// add the 'unknown' tab
		$headline = $this->_("This is a summary table of emails where the recipient could not be found in a receiving parent page. 
If you wish to create a receiving parent with an email address matching one of the 'to' addresses below then the related emails should be processed within a few minutes of saving the new parent page. <br/>");
		$footers[] = $this->addEmailsTab($form, $mailParser, 'unknown', 'Emails with unknown recipients', $headline);

		// Add the 'quarantine' tab
		$headline = $this->_("This is a summary table of emails which have been quarantined as being from a blocked sender or possibly a spoof or hacked email (intercepted with phishing added). 
The reason for the quarantining is shown. If you amend a check such that the email can be processed then that should happen within a few minutes.<br/>");
		$footers[] = $this->addEmailsTab($form, $mailParser, 'quarantine', $this->_('Quarantined emails'), $headline, $this->_('Reason'));

		// Add the 'bad' tab
		$headline = $this->_("This is a summary table of emails which have been rejected as bad. You can look at the raw content to try and identify the cause or just delete them. 
If you think they are good emails, you can try to reprocess them.<br/>");
		$footers[] = $this->addEmailsTab($form, $mailParser, 'bad', $this->_('Bad emails'), $headline, $this->_('Reason'));

		// Create a separate tab for maintenance
		$tab = new InputfieldWrapper();
		$tab->attr('id', 'maintenance');
		$tab->attr('title', $this->_('Maintenance / Troubleshooting'));
		$tab->attr('class', 'WireTab');

		$f = $this->modules->get('InputfieldMarkup');
		$f->value = $this->_('Manage orphaned email files and unprocessed queued files.');
		$tab->append($f);

		$f = $this->modules->get('InputfieldButton');
		$f->attr('id', 'maintenance');
		$f->attr('href', $this->page()->url . 'maintenance-troubleshooting/');
		$f->attr('value', 'Maintenance/Troubleshooting');
		$f->attr('class', 'uk-button uk-button-primary');
		$tab->append($f);

		$form->append($tab);

		return $form->render() . implode('; ', array_filter($footers));
	}

	/**
	 * Admin process page for maintenance and troubleshooting.
	 *
	 * @return mixed
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	public function ___executeMaintenanceTroubleshooting() {
		$this->modules->get('JqueryWireTabs');
		$mailParser = new MailMimeParser();

		$form = $this->modules->get("InputfieldForm");
		$form->attr('id', 'PipeEmailToPage');


		$footers = [];
		// Queue tab
		$headline = $this->_("This is a summary table of emails which have been queued. They should all be processed within a minute. 
If they ae not processed, it may be that the queue has been locked, in which case unlock the queue with the button above. Alternatively, you may delete emails.<br/>");
		$footers[] = $this->addEmailsTab($form, $mailParser, 'queue', $this->_('Queued emails'), $headline);

		//Orphans tab
		$headline = $this->_("This is a summary table of emails which have been processed, but for which no page exists. 
This may be because the page has been deleted without deleting the email file. You may reprocess or delete the email. 
Note that reprocessing may also cause consequential actions to be repeated (e.g forwarding).<br/>");
		$footers[] = $this->addEmailsTab($form, $mailParser, 'orphans', $this->_('Orphaned processed emails'), $headline);

		return $form->render() . implode('; ', array_filter($footers));
	}

	/**
	 * Adds a tab to the form displaying processed emails where a page has been created.
	 *
	 * This method creates a new tab in the form to display a summary table of processed emails.
	 * It retrieves the latest processed emails, builds a table with the results, and adds pagination if necessary.
	 *
	 * @param InputfieldForm $form The form to which the tab will be added.
	 */
	private function addProcessedTab($form) {
		// Create a new tab for processed emails
		$tab = new InputfieldWrapper();
		$tab->attr('id', 'processed');
		$tab->attr('title', $this->_('Processed emails'));
		$tab->attr('class', 'WireTab');
		$field = $this->modules->get("InputfieldMarkup");

		// Retrieve the latest processed emails
		$results = wire('pages')->find("template={$this->receivedTemplate}, sort=-created, parent!=7, limit=25, include=all");
		$out = '';
		$count = count($results);
		$start = $results->getStart();
		$limit = $results->getLimit();
		$total = $results->getTotal();
		$end = $start + $count;
		$pagerOut = '';

		// Check if there are any results
		if(count($results)) {
			// Build the table with the results
			$table = $this->buildTable($results);
			$tableOut = $table->render();
			$headline = $this->_('This is a summary table of received emails (latest first). You can sort (but only within a page) by clicking on the various headers.');
			if($this->retentionProcessed > 0) {
				$headline .= $this->_(' The raw email files will be removed after ') . $this->retentionProcessed . $this->_(' days after their date.');
			}
			if($this->reportPage && $this->modules->isInstalled('ProcessPageListerPro')) {
				$mailReceivedReport = $this->pages->get($this->reportPage)->url;
				$headline .= $this->_(" For a more flexible report where you can use various filters, ");
				$headline .= "<a href='$mailReceivedReport'>";
				$headline .= $this->_("click here");
				$headline .= "</a>.";
				$headline .= " Raw headers and text are only available if the processed email file has been retained.";
			}
			$headline .= '<hr/>' . sprintf($this->_('%1$d to %2$d of %3$d'), $start + 1, $end, $total);
			if($total > $limit) {
				/** @var MarkupPagerNav $pager */
				$pager = $this->wire()->modules->get('MarkupPagerNav');
				$pagerOut = $pager->render($results);
				$pageURL = $this->wire()->page->url;
				$pagerOut = str_replace($pageURL . "'", $pageURL . "?pageNum=1'", $pagerOut); // specifically identify page1
			}
		} else {
			$headline = $this->_('No results.');
			$tableOut = "<div class='ui-helper-clearfix'></div>";
		}

		// Combine the headline, pager, and table output
		$out .= $headline . $pagerOut . $tableOut;
		$field->value = $out;
		$tab->add($field);
		$form->append($tab);
	}

	/**
	 * This method creates a new tab in the form to display a summary table of email files of various types.
	 * (as distinct from the processedTab which shows pages created from the emails).
	 * Types are: queue, orphaned, unknown, bad and quarantine.
	 *
	 * @param $form
	 * @param $mailParser
	 * @return void
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	private function addEmailsTab($form, $mailParser, $tabId, $tabTitle, $headline, $commentsHeader = null) {
		if(!$commentsHeader) $commentsHeader = $this->_('Comments'); // can't assign argument to method call

		// Get all .eml files in the subdirectory
		$directory = ($tabId == 'orphans') ? 'processed' : $tabId;
		$camelDir = ucfirst($directory);
		$retentionPeriod = $this->{"retention$camelDir"};
		//bd($retentionPeriod, 'retentionPeriod');
		if($retentionPeriod > 0) {
			$headline .= $this->_(' The email files will be removed after ') . $retentionPeriod . $this->_(' days after their date.');
		}

		$files = glob(wire('config')->paths->assets . 'emailpipe/' . $directory . '/*.eml');
		$files = array_reverse($files);
		// For orphans, remove files with a corresponding page
		if($tabId == 'orphans') {
			foreach($this->pages()->find("template={$this->receivedTemplate}, parent!=7") as $page) {
				$filename = $page->meta('filename');
				$key = array_search($filename, $files);
				if($key !== false) {
					unset($files[$key]);
				}
			}
		}

		if(count($files) > 0) {
			// Initialize the output string with a description
			$out = $headline;

			// Create a new tab for the emails
			$tab = new InputfieldWrapper();
			$tab->attr('id', $tabId);
			$tab->attr('title', $tabTitle);
			$tab->attr('class', 'WireTab');

			// For the queue tab, include an unlock button
			if($tabId == 'queue') {
				$f = $this->modules->get('InputfieldButton');
				$f->attr('id', 'unlock');
				$f->attr('href', $this->page()->url . 'unlock-queue/');
				$f->attr('value', 'Unlock queue');
				$f->attr('class', 'uk-button uk-button-primary');
				$tab->append($f);
			}

			$field = $this->modules->get("InputfieldMarkup");

			// Create a table to display the email details
			$table = $this->wire('modules')->get("MarkupAdminDataTable");
			$tableHeaders = [$this->_("To"), $this->_("From"), $this->_("Subject"), $this->_("Date"), $commentsHeader, $this->_("Headers"), $this->_('Action')];
			$table->headerRow($tableHeaders);
			$table->setSortable(true);
			$table->set('encodeEntities', false);

			// Iterate through each email file
			foreach($files as $filename) {
				$handle = fopen($filename, "r");
				$message = $mailParser->parse($handle, false);
				$addresses = $this->extractEmailAddresses($message);
				$addressString = $addresses['addressString'];
				$from = $message->getHeader(HeaderConsts::FROM)->getEmail();
				$subject = $message->getSubject();

				// Get the received dates from the email headers
				$received = $message->getAllHeadersByName(HeaderConsts::RECEIVED);
				$dates = [];
				$comments = '-';
				if($tabId == 'quarantine') {
					$reasons = ($message->getAllHeadersByName('X-Quarantine-Reason'));
					$comments = ($reasons) ? end($reasons)->getValue() : '?';
				}
				if($tabId == 'bad') {
					$reasons = ($message->getAllHeadersByName('X-Bad-Reason'));
					$comments = ($reasons) ? end($reasons)->getValue() : '?';
				}

				foreach($received as $header) {
					$dates[] = $header->getDateTime();
				}
				$del = $this->deleteButton($filename);
				if($tabId == 'quarantine') {
					$extra = '<div>' . $this->unSpamButton($filename) . '</div>';
				} elseif($tabId == 'orphans') {
					$extra = '<div>' . $this->reprocessButton($filename) . '</div>';
				} else if($tabId == 'bad') {
					$text = $this->_("Are you sure you want to reprocess the email? \nThis may fail for the same reason as before.");
					$extra = '<div>' . $this->reprocessButton($filename, $text) . $this->confirmModal('reprocess') . '</div>';
				} else {
					$extra = '';
				}
				$link = $this->getFilenameLink($filename);
				// Create a table row with the email details
				$data = array(
					$addressString,
					$from,
					$subject,
					($dates && min($dates)) ? date_format(min($dates), 'd/m/Y (H:i)') : '?',
					$comments,
					$link,
					'<div>' . $del . '</div>' . $extra
				);
				$table->row($data);
			}

			// Add the table to the output string
			$out .= $table->render();
			$out .=   $this->confirmModal('unspam') . $this->confirmModal('reprocess') . $this->confirmModal('delete');
			$field->value = $out;
			$tab->add($field);
			$form->append($tab);
			return null;
		} else {
			// If there are no email files, display a message
			return $this->_('Nothing to display for ') . $tabTitle;
		}
	}

	/**
	 * Builds a table displaying processed email details where a page has been created.
	 *
	 * This method creates a table with columns for category, parent, title, to, from, text summary, images, files, created date, and a link to view the text.
	 * It iterates through the provided array of emails, extracts relevant details, and adds them as rows to the table.
	 *
	 * @param array $mailArray An array of email objects to be displayed in the table.
	 * @return MarkupAdminDataTable The table populated with email details.
	 */
	private function buildTable($mailArray) {

		$table = $this->wire('modules')->get("MarkupAdminDataTable");
		$table->headerRow([$this->_("Parent (Category)"), $this->_("Title"), $this->_("To") . '/' . $this->_("From"),
			$this->_("Text summary (hover to expand)"), $this->_("Images"), $this->_("Files"), $this->_("Created"),
			$this->_("Headers"), $this->_('Action')]);
		$table->setSortable(true);
		$table->set('encodeEntities', false);
		$table->addClass('uk-table uk-table-striped uk-table-hover uk-table-condensed uk-table-responsive');

		foreach($mailArray as $mail) {

			$body = '<div class="pipe-table-text uk-text-small">' . $mail->{$this->emailBodyField} . '</div>';
			$iframeContent = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
			$filename = ($mail->meta('filename') && $this->wire()->files->exists($mail->meta('filename'))) ? $mail->meta('filename') : '';
			$link = $this->getFilenameLink($filename);
			$del = $this->deleteButton($filename, $mail) . $this->confirmModal('delete');

			// Create table row
			$data = array(
				// Values with a string key are converted to a link: title => link

				['<a href="' . $this->config->urls->admin . 'page/edit/?id=' . $mail->parent()->id . '">' . $mail->parent()->title
					. '<span class="uk-text-small uk-text-italic" > (' . $mail->parent()->template->name . ')</span></a>', 'uk-table-shrink'],
				['<a href="' . $this->config->urls->admin . 'page/edit/?id=' . $mail->id . '">' . $mail->title . '</a>', 'uk-table-shrink'],

				['<div>' . $mail->{$this->addresseesField} . '</div><div>' . $this->_("From: ") . $mail->{$this->emailFromField} . '</div>', 'uk-table-shrink'],

				['<iframe srcdoc="' . $iframeContent . '" class="seamless" ></iframe>', 'uk-width-1-1'], // NB No sandbox as html has been purified
				[($mail->{$this->emailImagesField}) ? $mail->{$this->emailImagesField}->count() : '', 'uk-table-shrink'],
				[($mail->{$this->emailFilesField}) ? $mail->{$this->emailFilesField}->count() : '', 'uk-table-shrink'],
				[($mail->created) ? date('d/m/Y (H:i)', $mail->created) : '', 'uk-table-shrink'],
				[$link, 'uk-table-shrink'],
				$del
			);
			$table->row($data);
		}
		return $table;
	}

	/**
	 * Displays a modal with the email contents including raw headers
	 *
	 * @param $filename
	 * @return string
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	private function getFilenameLink($filename) {
		if($filename) {
			$href = $this->page()->url . 'mail-content/?filename=' . $filename;
			//Use the AdminInModal module if installed as it suppresses messages errors and warnings, which are unnecessary in this context
			if($this->wire()->modules->isInstalled('AdminInModal')) {
				$btn = new Page();
				$link = $btn->aim([
					'href' => $href,
					'header-text' => '',
					'text' => '',
					'suppress-notices' => 'messages warnings errors',
					'class' => "fa fa-file-text",
					'redirect' => '',
				]);
				//

			} else {
				$this->wire()->modules->get('JqueryUI')->use('modal');
				$link = "<a class='pw-modal' href='{$href}&modal=1'><i class='fa fa-file-text'></i></a>";
			}
		} else {
			$link = '';
		}
		return $link;
	}

	/**
	 * Button to delete email files (and matching pages if they exist)
	 *
	 * @param $filename
	 * @param $mail
	 * @return string
	 * @throws WireException
	 */
	private function deleteButton($filename, $mail = new NullPage()) {
		$js = $this->wire()->config->js('PipeEmailToPage');
		$js['confirmDelete'] = $this->_("Are you sure you want to delete the email?\nThis action cannot be undone");
		$this->wire()->config->js('PipeEmailToPage', $js);
		$href = $this->page()->url . 'delete-email/?id=' . $mail->id . '&filename=' . $filename . '&segment=' . $this->page()->urlSegment;
		return "<a class='delete-email' data-href='$href'><abbr title='Delete'><i class='fa fa-trash'></i></abbr></a>";
	}

	/**
	 * Button to remove emails from quarantine (permanently))
	 *
	 * @param $filename
	 * @return string
	 * @throws WireException
	 */
	private function unSpamButton($filename) {
		$js = $this->wire()->config->js('PipeEmailToPage');
		$js['confirmUnspam'] = $this->_("Opening the mail will remove the it from the quarantine. \nThis will place it in the 'Processed emails' and create a page from it. 
		You cannot then undo this action, only delete the email.\nOnly do this if you are sure the email is not a threat.");
		$this->wire()->config->js('PipeEmailToPage', $js);
		$href = $this->page()->url . 'unspam-email/?filename=' . $filename . '&segment=' . $this->page()->urlSegment;
		return "<a class='unspam-email' data-href='$href'><abbr title='Open'><i class='fa fa-envelope-open'></i></abbr></a>";
	}

	/**
	 * Button to reprocess the selected email file
	 *
	 * @param $filename
	 * @param $text
	 * @return string
	 * @throws WireException
	 */
	private function reprocessButton($filename, $text = null) {
		$js = $this->wire()->config->js('PipeEmailToPage');
		if($text) {
			$js['confirmReprocess'] = $text;
		} else {
			$js['confirmReprocess'] = $this->_("Are you sure you want to reprocess the email? \nThis will cause any consequential actions (e.g. forwarding) to be repeated.");
		}
		$this->wire()->config->js('PipeEmailToPage', $js);
		$href = $this->page()->url . 'reprocess-email/?filename=' . $filename . '&segment=' . $this->page()->urlSegment;
		return "<a class='reprocess-email' data-href='$href'><abbr title='Reprocess'><i class='fa fa-recycle'></i></abbr></a>";
	}


	/**
	 * Process page to render the email body for a given email ID.
	 *
	 * This method retrieves the email page using the provided ID,
	 * extracts the addressees and body fields, and returns the
	 * concatenated result with an HTML horizontal rule in between.
	 *
	 * @return string The concatenated addressees and body of the email.
	 */
	public function ___executeMailContent() {
		$filename = wire('input')->get('filename');
		if($this->wire()->files->exists($filename)) {
			$handle = fopen($filename, "r");
			$mailParser = new MailMimeParser();
			$message = $mailParser->parse($handle, false);
			$headers = $message->getAllHeaders();
			$out = '';
			foreach($headers as $header) {
				$out .= $header->getName() . ': ' . $header->getRawValue() . '<br/>'; //NB raw value needed to get complete header
			}

			return $out . $message->getContent() . $message->getHtmlContent();
			fclose($handle);
		} else {
			return 'File not found';
		}
	}

	/**
	 * Process page to delete an email file
	 *
	 * @return void
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	public function ___executeDeleteEmail() {
		// Before we delete the email, pop up an alert to confirm

		$id = wire('input')->get('id');
		$filename = wire('input')->get('filename');
		if($id != 0) {
			$mail = wire('pages')->get($id);
			if($mail->id) {
				$mail->trash();
			}
		}
		if($this->wire()->files->exists($filename)) {
			unlink($filename);
		}
		$redirect = wire('input')->get('segment');
		$this->session->redirect($this->page()->url . $redirect);
	}

	/**
	 * Process page to unspam an email file
	 *
	 * @return void
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	public function ___executeUnspamEmail() {
		$id = wire('input')->get('id');
		$filename = wire('input')->get('filename');
		if($this->wire()->files->exists($filename)) {
			$content = file_get_contents($filename);
			$created = pathinfo($filename)['filename'];
			$header = "X-NotSpam: " . $created;
			$headerEndPos = strpos($content, "\n\n");
			if($headerEndPos !== false) {
				$content = substr_replace($content, $header, $headerEndPos + 1, 0);
				file_put_contents($filename, $content);
			}
		}
		$this->reprocessFile($filename, 'quarantine');
		$redirect = wire('input')->get('segment');
		$this->session->redirect($this->page()->url . $redirect);
	}

	/**
	 * Remove the lock file to allow the queue to be processed
	 *
	 * @return void
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	public function ___executeUnlockQueue() {
		$id = wire('input')->get('id');
		$lockFile = wire('config')->paths->assets . 'cache/LazyCronLock.cache';
		if($this->wire()->files->exists($lockFile)) {
			unlink($lockFile);
		}
		$redirect = wire('input')->get('segment');
		$this->session->redirect($this->page()->url . $redirect);
	}

	/**
	 * Process page to reprocess an email file
	 *
	 * @return void
	 * @throws WireException
	 * @throws WirePermissionException
	 */
	public function ___executeReprocessEmail() {
		$id = wire('input')->get('id');
		$filename = wire('input')->get('filename');
		//bd($filename, 'reprocess filename');
		$dir = pathinfo($filename)['dirname'];
		$arr = explode('/', $dir);
		$endDir = end($arr);
		$this->reprocessFile($filename, $endDir);
		$redirect = wire('input')->get('segment');
		$this->session->redirect($this->page()->url . $redirect);
	}

	/**
	 * Hidden modal for confirmation of delete, unspam or reprocess (shown by js)
	 *
	 * @param $button
	 * @return string
	 */
	protected function confirmModal($button) {
		return '
        <div id="confirmation-modal-' . $button . '" style="display: none;">
            <div class="modal-overlay"></div>
            <div class="modal-content">
                <p id="modal-message-' . $button . '"></p>
                <button id="modal-confirm">Yes</button>
                <button id="modal-cancel">No</button>
            </div>
        </div>
    ';
	}


	/**
	 * Check if the sender is allowed (default is to allow).
	 *
	 * This method checks if the sender's email address or domain is in the blacklist or whitelist.
	 * If the sender is in the blacklist, the method returns false.
	 * If the sender is in the whitelist, the method returns true.
	 * If the sender is not in either list, the method returns the default value.
	 *
	 * You can hook this method in your `init.php` file to return a different result.
	 * In your hook, retrieve `$from` as `$event->arguments(0)`.
	 * If you hook before this method, set `$event->arguments(3)` to true or false; the white and black lists will be applied afterwards.
	 * If you hook after this method, you can ignore the white and black lists and apply your own logic,
	 * and set `$event->return` to false if the sender is not valid.
	 * You can use `arguments(1)` to pass the parent page to the hook in case you want different behavior for different parent pages.
	 * You can use `arguments(2)` to pass an error message from the hook.
	 *
	 * @param string $from The sender's email address.
	 * @param Page $parentPage The parent page (default is a new NullPage).
	 * @param string $errorMessage The error message to set if the sender is not valid (default is 'Invalid sender').
	 * @param bool $default The default return value if the sender is not in the whitelist or blacklist (default is true).
	 * @return bool True if the sender is allowed, false otherwise.
	 */
	public function ___checkSender($from, $parentPage = new NullPage, $errorMessage = 'Invalid sender', $default = true) {
		$pipeConfig = wire()->modules->getConfig($this->className);
		$from = strtolower($from);
		$fromDomain = explode('@', $from)[1];
		$blackList = explode("\n", strtolower($pipeConfig['blackList']));
		$whiteList = explode("\n", strtolower($pipeConfig['whiteList']));
		wire()->log->save('emailpipe', 'from: ' . $from . ' fromDomain: ' . $fromDomain . ' blackList: ' . implode(', ', $blackList) . ' whiteList: ' . implode(', ', $whiteList));
		if(in_array($from, $blackList) || in_array($fromDomain, $blackList)) return false;
		if(in_array($from, $whiteList) || in_array($fromDomain, $whiteList)) return true;
		$this->reason = $errorMessage;
		return $default;
	}

	/**
	 * Process the email message
	 *
	 * This method processes an email message from a given file. It performs the following steps:
	 * 1. Logs the start of processing.
	 * 2. Parses the email message.
	 * 3. Retrieves the recipient and sender addresses from the email headers.
	 * 4. Performs SPF and DKIM checks on the email.
	 * 5. Identifies the parent pages for the email based on the recipient address.
	 * 6. Checks if the sender is allowed to send emails to the identified parent pages.
	 * 7. Processes the email and saves it to the appropriate parent pages.
	 * 8. Moves the processed email file to the 'processed' directory.
	 * 9. Handles any exceptions that occur during processing and moves the email file to the 'bad' directory if necessary.
	 *
	 * @param string $filename The path to the email file to be processed.
	 */
	public function processMessage($filename) {
		// Log the start of processing
		wire()->log->save('emailpipe', $this->_('Processing file: ') . $filename);

		// Get the module configuration
		$pipeConfig = wire()->modules->getConfig($this->className);
		$mailParser = new MailMimeParser();

		try {
			require_once __DIR__ . '/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php'; //Get the HTMLPurifier library
			// Open the email file and parse the message
			$handle = fopen($filename, "r");
			$message = $mailParser->parse($handle, false);

			// Retrieve the recipient and sender addresses from the email headers
			$recipient = $this->getHeaderValue($message, 'X-Recipient-Address');
			$sender = $this->getHeaderValue($message, 'X-Sender-Address');
			wire()->log->save('emailpipe', $this->_('Recipient in processMessage: ') . $recipient);
			wire()->log->save('emailpipe', $this->_('Sender in processMessage: ') . $sender);

			// Get the sender's email address
			$from = $sender ?: $message->getHeader(HeaderConsts::FROM)->getEmail();
			if(!$from) {
				fclose($handle);
				$this->bad($filename, $message, $from, $this->_('No sender'));
				return;
			}

			// Check that the mail hasn't been marked by user as not being spam
			$byPassSpam = false;
			$notSpam = $message->getHeader('X-NotSpam');
			if($notSpam) {
				$notSpamTime = $notSpam->getValue();
				$created = pathinfo($filename)['filename'];
				$byPassSpam = ($created == $notSpamTime);
				wire()->log->save('emailpipe', sprintf($this->_('Not spam time: %s, created time: %s, bypass spam: %s'), $notSpamTime, $created, $byPassSpam));
			}

			// Perform SPF and DKIM checks on the email
			if(!$byPassSpam && !$this->performSPFAndDKIMChecks($message, $from, $filename, $handle)) {
				return;
			}

			// Identify the parent pages for the email based on the recipient address
			$parentPages = $this->getParentPages($message, $recipient, $pipeConfig, $filename, $handle);
			if(!$parentPages) {
				return;
			}

			// Check if the sender is allowed to send emails to the identified parent pages
			foreach($parentPages as $parentPage) {
				if(!$byPassSpam && !$this->checkSender($from, $parentPage)) {
					fclose($handle);
					wire()->log->save('emailpipe', sprintf($this->_('Invalid sender: %s for parent page: %s'), $from, $parentPage->title));
					$reason = isset($this->reason) ? $this->reason : $this->_('Invalid sender');
					$this->quarantine($filename, $from, $reason);
					return;
				}
			}

			// Process the email and save it to the appropriate parent pages
			$this->processToPage($message, $parentPages, $pipeConfig, $filename, $handle);

			// Close the file handle and move the processed email file to the 'processed' directory
			fclose($handle);
			$processedFilename = str_replace('queue', 'processed', $filename);
			rename($filename, $processedFilename);

		} catch(\Throwable $e) {
			// Handle any exceptions that occur during processing
			fclose($handle);
			$reason = sprintf($this->_('%s\n Could not process file: %s'), $e, $filename);
			$this->bad($filename, '', '', $reason);
		}
	}

	/**
	 * Retrieves the value of a specified header from an email message.
	 *
	 * This method checks if the specified header exists in the email message.
	 * If the header exists, it returns its value. Otherwise, it returns an empty string.
	 *
	 * @param Message $message The email message object.
	 * @param string $headerName The name of the header to retrieve.
	 * @return string The value of the specified header, or an empty string if the header does not exist.
	 */
	private function getHeaderValue($message, $headerName) {
		return ($message->getHeader($headerName)) ? $message->getHeader($headerName)->getValue() : '';
	}

	/**
	 * Performs SPF and DKIM checks on the email message.
	 *
	 * This method checks the SPF and DKIM signatures of the email message to ensure its authenticity.
	 * If the SPF or DKIM check fails, the email is quarantined.
	 *
	 * @param Message $message The email message object.
	 * @param string $from The sender's email address.
	 * @param string $filename The path to the email file being processed.
	 * @param resource $handle The file handle for the email file.
	 * @return bool True if both SPF and DKIM checks pass, false otherwise.
	 */
	private function performSPFAndDKIMChecks($message, $from, $filename, $handle): bool {
		$pipeConfig = wire()->modules->getConfig($this->className);
		$received = $message->getAllHeadersByName(HeaderConsts::RECEIVED);
		if(count($received) > 1) {
			$domain = explode('@', $from)[1];
			if($pipeConfig['spfCheck'] && !$this->checkSPF($message, $received, $domain)) {
				fclose($handle);
				$this->quarantine($filename, $from, $this->_('SPF check failed'));
				return false;
			}
			if($pipeConfig['dkimCheck'] && !$this->checkDKIM($message, $filename, $handle, $from)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks the SPF (Sender Policy Framework) record of the email message.
	 *
	 * This method verifies the SPF record of the email message to ensure that the sender's IP address is authorized to send emails on behalf of the domain.
	 * If the SPF check fails, it iterates through the received headers to perform additional checks.
	 *
	 * @param Message $message The email message object.
	 * @param array $received An array of received headers from the email message.
	 * @param string $domain The domain of the sender's email address.
	 * @return bool True if the SPF check passes, false otherwise.
	 */
	private function checkSPF($message, $received, $domain) {
		$spf = ($message->getHeader('Received-SPF')) ? $message->getHeader('Received-SPF')->getAllParts()[0]->getName() : '';
		if(str_contains($spf, 'pass') === false) {
			foreach($received as $header) {
				$fromAddress = $header->getFromAddress();
				if($fromAddress) {
					$dnsRecordGetter = new SPFCheck(new DNSRecordGetter());
					$result = $dnsRecordGetter->getIPStringResult($fromAddress, $domain);
					if($result == \Mika56\SPFCheck\Model\Result::SHORT_PASS) {
						return true;
					}
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Checks the DKIM (DomainKeys Identified Mail) signature of the email message.
	 *
	 * This method verifies the DKIM signature of the email message to ensure its authenticity.
	 * If the DKIM check fails, the email is quarantined.
	 *
	 * @param Message $message The email message object.
	 * @param string $filename The path to the email file being processed.
	 * @param resource $handle The file handle for the email file.
	 * @param string $from The sender's email address.
	 * @return bool True if the DKIM check passes, false otherwise.
	 */
	private function checkDKIM($message, $filename, $handle, $from) {
		$message  = file_get_contents($filename);
		//bd($message, 'message');
		$dkimValidator = new Validator($message);
		try {
			$dkimResult = $dkimValidator->validateWithReasons();  // validateWithReasons is a custom method not in the original library
			if(!$dkimResult['valid']) {
				fclose($handle);
				$reasons = implode(', ', array_unique($dkimResult['reasons']));
				$this->quarantine($filename, $from, $this->_('DKIM check failed: ') . $reasons);
				return false;
			}
		} catch(\Throwable $e) {
			fclose($handle);
			$this->quarantine($filename, $from, sprintf($this->_('DKIM exception - %s'), $e->getMessage()));
			return false;
		}
		return true;
	}

	/**
	 * Retrieves the parent pages for the email based on the recipient address.
	 *
	 * This method identifies the parent pages for the email by checking the recipient address
	 * against the configured email-to fields and parent templates. If no parent templates or
	 * valid email-to fields are configured, or if no parent pages are found, the email file
	 * is moved to the 'unknown' subdirectory.
	 *
	 * @param Message $message The email message object.
	 * @param string $recipient The recipient email address.
	 * @param array $pipeConfig The module configuration array.
	 * @param string $filename The path to the email file being processed.
	 * @param resource $handle The file handle for the email file.
	 * @return PageArray|null The parent pages for the email, or null if no parent pages are found.
	 */
	private function getParentPages($message, $recipient, $pipeConfig, $filename, $handle) {
		$parentTemplates = implode('|', $pipeConfig['categoryTemplates']);
		if(!$parentTemplates) {
			wire()->log->save('emailpipe', $this->_('No parent templates selected. Moving file to "unknown" subdirectory'));
			fclose($handle);
			$unknownFilename = str_replace('queue', 'unknown', $filename);
			rename($filename, $unknownFilename);
			return null;
		}

		$emailToFields = ($pipeConfig['emailToFields'] && wire()->fields->get($pipeConfig['emailToFields'])) ? wire()->fields->get($pipeConfig['emailToFields']) : null;
		$emailToFieldNames = [];
		if($emailToFields) {
			foreach($emailToFields as $emailToField) {
				$emailToFieldNames[] = ($emailToField->name) ?? null;
			}
		}
		array_filter($emailToFieldNames);
		if(!$emailToFieldNames) {
			wire()->log->save('emailpipe', $this->_('No valid email to field selected in module configuration. Moving file to "unknown" subdirectory'));
			fclose($handle);
			$unknownFilename = str_replace('queue', 'unknown', $filename);
			rename($filename, $unknownFilename);
			return null;
		}
		$emailToSelector = implode('|', $emailToFieldNames);
		if($recipient) {
			$parentPages = wire()->pages->find("template=$parentTemplates, $emailToSelector~=$recipient");
		} else {
			$parentInfo = $this->getParentFromAddress($message, $filename, $emailToSelector, $parentTemplates);
			if($parentInfo == 'duplicate') return null;
			$recipient = $parentInfo['recipient'];
			$parentPages = $parentInfo['pages'];
		}

		if(!$parentPages || $parentPages->count() == 0) {
			fclose($handle);
			wire()->log->save('emailpipe', $this->_('No parent pages found. Moving file to "unknown" subdirectory'));
			$unknownFilename = str_replace('queue', 'unknown', $filename);
			rename($filename, $unknownFilename);
			return null;
		}

		return $parentPages;
	}

	/**
	 * Processes the email message and saves it to the appropriate parent pages.
	 *
	 * This method iterates through the provided parent pages and creates a new page for each parent.
	 * It sets the template for the new page, populates it with email data, and saves the page.
	 * If no received template is selected, the email file is moved to the 'unknown' subdirectory.
	 *
	 * @param Message $message The email message object.
	 * @param PageArray $parentPages The parent pages to which the email will be saved.
	 * @param array $pipeConfig The module configuration array.
	 * @param string $filename The path to the email file being processed.
	 * @param resource $handle The file handle for the email file.
	 */
	private function processToPage($message, $parentPages, $pipeConfig, $filename, $handle) {
		foreach($parentPages as $parentPage) {
			$mailReceivedPage = new DefaultPage();
			$mailReceivedPage->of(false);
			if(!wire()->templates->get($pipeConfig['receivedTemplate'])) {
				wire()->log->save('emailpipe', $this->_('No received template selected. Moving file to "unknown" subdirectory'));
				$unknownFilename = str_replace('queue', 'unknown', $filename);
				rename($filename, $unknownFilename);
				return;
			}
			$mailReceivedPage->template = wire()->templates->get($pipeConfig['receivedTemplate']);
			$this->populateMailReceivedPage($mailReceivedPage, $message, $pipeConfig, $parentPage);
			$mailReceivedPage->save();
			$processedFilename = str_replace('queue', 'processed', $filename);
			$mailReceivedPage->meta()->set('filename', $processedFilename);
		}
	}

	/**
	 * Populates the received email page with data from the email message.
	 *
	 * This method sets the parent page, email addresses, subject, body, and attachments
	 * for the received email page based on the provided email message and configuration.
	 *
	 * @param Page $mailReceivedPage The page object representing the received email.
	 * @param Message $message The email message object.
	 * @param array $pipeConfig The module configuration array.
	 * @param Page $parentPage The parent page to which the received email page will be assigned.
	 */
	private function populateMailReceivedPage($mailReceivedPage, $message, $pipeConfig, $parentPage) {
		$emailFrom = (wire()->fields->get($pipeConfig['emailFromField'])) ? wire()->fields->get($pipeConfig['emailFromField'])->name : '';
		$addressees = (wire()->fields->get($pipeConfig['addresseesField'])) ? wire()->fields->get($pipeConfig['addresseesField'])->name : '';
		$emailSubject = (wire()->fields->get($pipeConfig['emailSubjectField'])) ? wire()->fields->get($pipeConfig['emailSubjectField'])->name : '';
		$emailBodyField = wire()->fields->get($pipeConfig['emailBodyField']);
		$emailBody = ($emailBodyField) ? $emailBodyField->name : '';

		// purify the html before saving it
		$dirty_html = $message->getHtmlContent();
		$clean_html = $this->cleanHtml($dirty_html);

		$body = ($emailBodyField && isset($emailBodyField->contentType) && $emailBodyField->contentType == 1 && $this->getHeaderValue($message, 'Content-Type') != 'text/plain') ? $clean_html : $message->getTextContent();
		$emailImages = (wire()->fields->get($pipeConfig['emailImagesField'])) ? wire()->fields->get($pipeConfig['emailImagesField'])->name : '';
		$emailFiles = (wire()->fields->get($pipeConfig['emailFilesField'])) ? wire()->fields->get($pipeConfig['emailFilesField'])->name : '';

		$mailReceivedPage->parent = $parentPage;
		if($addressees) $mailReceivedPage->$addressees = $this->extractEmailAddresses($message)['addressString'];
		if($emailSubject) $mailReceivedPage->$emailSubject = $message->getSubject();
		if($emailBody) $mailReceivedPage->$emailBody = $body;
		if($emailFrom) $mailReceivedPage->$emailFrom = $message->getHeader(HeaderConsts::FROM)->getEmail();
		$mailReceivedPage->save();
		$this->saveAttachments($message, $mailReceivedPage, $emailImages, $emailFiles);
	}

	/**
	 * Cleans the HTML content of the email message and adds target="_blank" to all anchor tags
	 * Hookable so can be replaced by another method.
	 *
	 * @param $dirty_html
	 * @return string
	 */
	protected function ___cleanHtml($dirty_html) {
		//	wire()->log->save('emailpipe', $this->_('Dirty HTML: ') . $dirty_html);
		// add target="_blank" to all anchor tags (otherwise the link opens in the iFrame)
		$htmlPurifierConfiguration = HTMLPurifier_Config::createDefault();
		$htmlDef = $htmlPurifierConfiguration->getHTMLDefinition(true);
		$anchor = $htmlDef->addBlankElement('a');
		$anchor->attr_transform_post[] = new HTMLPurifier_AttrTransform_TargetBlank();
// now purify the html
		$purifier = new HTMLPurifier($htmlPurifierConfiguration);
		$clean_html = $purifier->purify($dirty_html);
//	wire()->log->save('emailpipe', $this->_('Cleaned HTML: ') . $clean_html);
		return $clean_html;
	}

	/**
	 * Saves the attachments from the email message to the specified fields in the received email page.
	 *
	 * This method iterates through all attachment parts of the email message, determines the appropriate field
	 * (image or file) based on the attachment's file extension, and saves the attachment to the corresponding field
	 * in the received email page.
	 *
	 * @param Message $message The email message object containing the attachments.
	 * @param Page $mailReceivedPage The page object representing the received email.
	 * @param string $emailImages The name of the field to save image attachments.
	 * @param string $emailFiles The name of the field to save file attachments.
	 */
	private function saveAttachments($message, $mailReceivedPage, $emailImages, $emailFiles) {
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
			$ext = pathinfo($attachFilename, PATHINFO_EXTENSION);
			if(in_array(strtolower($ext), ['gif', 'jpg', 'jpeg', 'png', 'peg'])) {
				$fileFieldName = $emailImages;
				$fileType = 'image';
			} else {
				$fileFieldName = $emailFiles;
				$fileType = 'file';
			}
			if(!$fileFieldName) {
				wire()->log->save('emailpipe', $this->_('No field selected for %s attachments. Unable to save.', $fileType));
				continue;
			}
			$path = wire()->config->paths->files . $mailReceivedPage->id . '/';
			$out = fopen($path . $attachFilename, 'w');
			$str = $part->getBinaryContentResourceHandle();
			stream_copy_to_stream($str, $out);
			fclose($str);
			fclose($out);
			$mailReceivedPage->$fileFieldName->add($path . $attachFilename);
			$mailReceivedPage->save();
		}
	}

	/**
	 * Retrieves the parent pages for the email based on the 'to' and 'cc' addresses.
	 * This is only used if the recipient address was not found in the email envelope and placed in the message as a custom header.
	 *
	 * This method identifies the parent pages for the email by checking the address
	 * against the configured email-to fields and parent templates. It also caches the email ID
	 * to prevent processing duplicate emails.
	 *
	 * @param Message $message The email message object.
	 * @param string $emailToSelector The selector for the email-to fields.
	 * @param string $parentTemplates The templates for the parent pages.
	 * @return array|bool An array containing the recipient email and unique parent pages, or false if no parent pages are found.
	 */
	public function getParentFromAddress($message, $filename, $emailToSelector, $parentTemplates) {

		$id = $message->getHeader(HeaderConsts::RECEIVED)->getValueFor('id');
		// $id = $message->getHeader(HeaderConsts::MESSAGE_ID)->getValue();
		$cache = $this->wire()->cache;
		$cacheNs = 'PETP_email_id';
		// if(!is_array($cache->get($cacheKey))) $cache->delete($cacheKey);
		$cachedId = ($cache->getFor($cacheNs, $id)) ?? [];
		if($cachedId) {
			$duplicate = true;
			wire()->log->save('emailpipe', sprintf($this->_('Duplicate email: %s'), $id));
		} else {
			$duplicate = false;
			wire()->log->save('emailpipe', sprintf($this->_('New email: %s'), $id));
			$cachedId['subject'] = $message->getSubject();
			$cache->saveFor($cacheNs, $id, $cachedId, 3600); // expire the cache after 1 hour - there should be any duplicates after this time!
		}

		$parentPages = new PageArray();
		$toEmail = '';
		$allAddresses = $this->extractEmailAddresses($message);
		$addresses = array_merge($allAddresses['to'], $allAddresses['cc']);
		//bd($addresses, 'addresses');
		foreach($addresses as $address) {
			$toEmail = $address->getEmail();
			if(!isset($cachedId['addressed'])) $cachedId['addressed'] = [];
			if(in_array($toEmail, $cachedId['addressed'])) {
				continue;
			}
			$cachedId['addressed'][] = $toEmail;
			$cache->saveFor($cacheNs, $id, $cachedId);
			//bd($toEmail, 'toEmail');
			$parentPages = pages()->find("template=$parentTemplates, $emailToSelector~=$toEmail");
			if($parentPages->count() == 0) {
				return false;
			} else {
				break;
			}
		}
		if($duplicate && count($addresses) > 0 && $parentPages->count() == 0)  {  //duplicate message where all addresses have already been processed
			if($this->duplicate($message, $filename)) return 'duplicate';
		}

		return ['recipient' => $toEmail, 'pages' => $parentPages->unique()];
	}

	/**
	 * This method retrieves the 'To', 'Cc', and 'Bcc' addresses from the email message headers.
	 *
	 * @param Message $message The email message object.
	 * @return array An associative array containing the extracted email addresses and their string representations
	 */
	protected function extractEmailAddresses($message) {
		$to = ($message->getHeader(HeaderConsts::TO)) ? $message->getHeader(HeaderConsts::TO)->getAddresses() : [];
		$cc = ($message->getHeader(HeaderConsts::CC)) ? $message->getHeader(HeaderConsts::CC)->getAddresses() : [];
		$bcc = ($message->getHeader(HeaderConsts::BCC)) ? $message->getHeader(HeaderConsts::BCC)->getAddresses() : [];
		$toString = ($to) ? implode(', ', $to) : '';
		$ccString = ($cc) ? '; cc: ' . implode(', ', $cc) : '';
		$bccString = ($bcc) ? '; bcc: ' . implode(', ', $bcc) : '';
		$addressString = 'To: ' . $toString . $ccString . $bccString;

		return [
			'to' => $to,
			'cc' => $cc,
			'bcc' => $bcc,
			'toString' => $toString,
			'ccString' => $ccString,
			'bccString' => $bccString,
			'addressString' => $addressString
		];
	}

	/**
	 * Quarantines the email file by adding a quarantine reason header and moving it to the 'quarantine' subdirectory.
	 *
	 * This method logs the quarantine action, adds a custom header with the quarantine reason to the email file,
	 * and moves the file from the 'queue' subdirectory to the 'quarantine' subdirectory.
	 *
	 * @param string $filename The path to the email file being quarantined.
	 * @param Message $message The email message object.
	 * @param string $from The sender's email address.
	 * @param string $reason The reason for quarantining the email.
	 */
	public function quarantine($filename, $from = '', $reason = '') {
		wire()->log->save('emailpipe', sprintf($this->_('Quarantining. Reason: %s. From: %s. Moving file to "quarantine" subdirectory'), $reason, $from));
		try {
			$content = file_get_contents($filename);
			$header = "X-Quarantine-Reason: " . $reason . "\n";
			$headerEndPos = strpos($content, "\n\n");
			if($headerEndPos !== false) {
				$content = substr_replace($content, $header, $headerEndPos + 1, 0);
			} else {
				wire()->log->save('emailpipe', $this->_('No header end found in file: %s', $filename));
			}
			file_put_contents($filename, $content);
		} catch(\Throwable $e) {
			wire()->log->save('emailpipe', sprintf($this->_('Error in quarantine: %s'), $e->getMessage()));
		}
		$quarantineFilename = str_replace('queue', 'quarantine', $filename);
		rename($filename, $quarantineFilename);
	}

	/**
	 * Deletes duplicate emails, identified either by matching file names or matching id headers.
	 * NB Duplicates can genuinely exist if there are multiple recipients so this should only be called when it is certain that all recipients have been processed..
	 *
	 * @param $message
	 * @param $filename
	 * @return true|void
	 * @throws WireException
	 */
	protected function duplicate($message, $filename) {
		// If the file with the same id exists in the processed subdirectory, delete it and return true, otherwise return false
		$processedFilename = str_replace('queue', 'processed', $filename);
		if(file_exists($processedFilename)) {
			unlink($processedFilename);
			wire()->log->save('emailpipe', $this->_('Duplicate email: ') . $filename . $this->_(' - deleted'));
			return true;
		}
		// It may be that the file has a slighly differnt name (delay in server sending) so check for processed files with identical header ids
		$mailParser = new MailMimeParser();
		$files = glob(wire('config')->paths->assets . 'emailpipe/processed/*.eml');
		$files = array_reverse($files); // reverse the array so that the most recent files are checked first - quicker to find a match
		foreach($files as $file) {
			$handle = fopen($file, "r");
			$processedMessage = $mailParser->parse($handle, false);
			$processedId = $processedMessage->getHeader(HeaderConsts::RECEIVED)->getValueFor('id');
			$receivedId = $message->getHeader(HeaderConsts::RECEIVED)->getValueFor('id');
			if($processedId == $receivedId) {
				fclose($handle);
				unlink($filename);
				wire()->log->save('emailpipe', $this->_('Duplicate email - id: ') . $receivedId . $this->_(' - deleted'));
				return true;
			}
			fclose($handle);
		}

	}

	/**
	 * Handles bad email files by adding a bad reason header and moving them to the 'bad' subdirectory.
	 *
	 * This method logs the bad file action, adds a custom header with the bad reason to the email file,
	 * and moves the file from the 'queue' subdirectory to the 'bad' subdirectory.
	 *
	 * @param string $filename The path to the email file being marked as bad.
	 * @param Message $message The email message object.
	 * @param string $from The sender's email address.
	 * @param string $reason The reason for marking the email as bad.
	 */
	protected function bad($filename, $message, $from, $reason) {
		// Quit if the file does not exist
		if(!file_exists($filename)) return;

		// Log the bad file action
		wire()->log->save('emailpipe', sprintf($this->_('Bad file. Reason: %s. From: %s. Moving file to "bad" subdirectory'), $reason, $from));
		try {
			// Read the content of the email file
			$content = file_get_contents($filename);
			// Add a custom header with the bad reason
			$header = "X-Bad-Reason: " . $reason . "\n";
			$headerEndPos = strpos($content, "\n\n");
			if($headerEndPos !== false) {
				$content = substr_replace($content, $header, $headerEndPos + 1, 0);
			} else {
				wire()->log->save('emailpipe', $this->_('No header end found in file: %s', $filename));
			}
			// Write the modified content back to the file
			file_put_contents($filename, $content);
		} catch(\Throwable $e) {
			// Log any errors that occur during the process
			wire()->log->save('emailpipe', sprintf($this->_('Error in bad: %s'), $e->getMessage()));
		}
		// Move the file to the 'bad' subdirectory
		$badFilename = str_replace('queue', 'bad', $filename);
		rename($filename, $badFilename);
	}

	/**
	 * Set default configuration values
	 *
	 * @return array
	 */
	protected function setDefaults() {
		return [
			'categoryTemplates' => [],
			'emailToFields' => '',
			'receivedTemplate' => '',
			'emailFromField' => '',
			'addresseesField' => '',
			'emailSubjectField' => '',
			'emailBodyField' => '',
			'emailImagesField' => '',
			'emailFilesField' => '',
			'retentionQuarantine' => 0,
			'retentionUnknown' => 0,
			'retentionBad' => 0,
			'retentionProcessed' => 0,
			'retentionOrphans' => 0,
			'spfCheck' => true,
			'dkimCheck' => true,
			'blackList' => '',
			'whiteList' => ''
		];
	}

	/**
	 * Get module configuration inputs
	 *
	 * As an alternative, configuration can also be specified in an external file
	 * with a PHP array. See an example in the /extras/ProcessHello.config.php file.
	 *
	 * @param InputfieldWrapper $inputfields The inputfields wrapper to which the configuration fields will be added.
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$modules = $this->wire()->modules;
		$data = array_merge($this->setDefaults(), $modules->getConfig($this));
		$inputfields->wrapAttr('autocomplete', 'off');

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
		$f->label = $this->_('Email Category Templates');
		$f->description = $this->_('Select one or more templates to use for the parents of the received emails');
		$f->notes = $this->_('Submit changes to see selectable fields');
		$f->columnWidth = 50;
		$f->addOptions($this->templates->find('')->explode('name', ['key' => 'id']));
		$f->value = $data[$f_name];
		$parents->add($f);

		/**
		 * Configuration field for email field
		 * The field to use for the email address that the email is sent to
		 * This should be a field that is in all of the selected templates and should be of type 'email'
		 */
		$f = $this->modules->get('InputfieldAsmSelect');
		$f_name = 'emailToFields';
		$f->name = $f_name;
		$f->label = $this->_('Email To Fields');
		$f->description = $this->_('Select one or more fields to use for the recipient email address(es) to be captured');
		$f->notes = $this->_('At least one of these fields should in any selected template and should be of type "email", "text" or "textarea" (plain text)');
		$f->columnWidth = 50;
		if($this->categoryTemplates) {
			foreach($this->categoryTemplates as $templateId) {
				$template = $this->templates->get($templateId);
				foreach($this->fields->find("type=FieldtypeEmail|FieldtypeText|FieldtypeTextarea") as $field) {
					if($template && $template->fields->has($field)) {
						$f->addOption($field->id, $field->name);
					}
				}
			}
		}
		$f->value = $data[$f_name];
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
		$f->label = $this->_('Received email template');
		$f->description = $this->_('Select the template to use for the received emails');
		$f->notes = $this->_('Submit changes to see selectable fields');
		$f->columnWidth = 100;
		$f->addOptions($this->templates->find('')->explode('name', ['key' => 'id']));
		$f->value = $data[$f_name];
		$mails->add($f);

		/**
		 * Configuration field for email field
		 * The field to use for the sending email address
		 * This should be a field of type 'email' or 'text'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailFromField';
		$f->name = $f_name;
		$f->label = $this->_('Email From Field');
		$f->description = $this->_('Select the field to use to store the email address that the email was sent from');
		$f->notes = $this->_('This should be a field that is in the selected template and should be of type "email" or "text"');
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeEmail") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $data[$f_name];
		$mails->add($f);

		/**
		 * Configuration field for addressees field
		 * The field to use for the receiving email addresses
		 * This should be a text field
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'addresseesField';
		$f->name = $f_name;
		$f->label = $this->_('Addressees Field');
		$f->description = $this->_('Select the field to use to store the addresses that the email was sent/copied to');
		$f->notes = $this->_("This should be a field that is in the selected template and should be a text or textarea field. \n
		The addresses will be stored as a string with each address separated by a comma and annotated with 'to:', 'cc:' or 'bcc:'");
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeText|FieldtypeTextarea") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $data[$f_name];
		$mails->add($f);

		/**
		 * Configuration field for title field
		 * The field to use for the email subject
		 * This should be a field of type 'PageTitle'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailSubjectField';
		$f->name = $f_name;
		$f->label = $this->_('Email Subject Field');
		$f->description = $this->_('Select the field to use to store the email subject');
		$f->notes = $this->_('This should be a field that is in the selected template and should be of type \'PageTitle\'');
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypePageTitle") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $data[$f_name];
		$mails->add($f);

		/**
		 * Configuration field for body field
		 * The field to use for the email body
		 * This should be a field of type 'Textarea'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailBodyField';
		$f->name = $f_name;
		$f->label = $this->_('Email Body Field');
		$f->description = $this->_('Select the field to use to store the email body');
		$f->notes = $this->_('This should be a field that is in the selected template and should be of type \'Textarea\'');
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeTextarea") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $data[$f_name];
		$mails->add($f);

		/**
		 * Configuration field for images field
		 * The field to use for the email image attachments
		 * This should be a field of type 'Images'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailImagesField';
		$f->name = $f_name;
		$f->label = $this->_('Email Images Field');
		$f->description = $this->_('Select the field to use to hold the email image attachments');
		$f->notes = $this->_('This should be a field that is in the selected template and should be of type \'Images\'');
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeImage") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $data[$f_name];
		$mails->add($f);

		/**
		 * Configuration field for files field
		 * The field to use for the email file attachments
		 * This should be a field of type 'Files'
		 */
		$f = $this->modules->get('InputfieldSelect');
		$f_name = 'emailFilesField';
		$f->name = $f_name;
		$f->label = $this->_('Email Files Field');
		$f->description = $this->_('Select the field to use to hold the email file attachments');
		$f->notes = $this->_('This should be a field that is in the selected template and should be of type \'Files\'');
		$f->columnWidth = 50;
		$template = $this->templates->get($this->receivedTemplate);
		foreach($this->fields->find("type=FieldtypeFile") as $field) {
			if($template && $template->fields->has($field)) {
				$f->addOption($field->id, $field->name);
			}
		}
		$f->value = $data[$f_name];
		$mails->add($f);

		// Black and white lists
		/** @var InputfieldFieldset $lists */
		$lists = $this->wire(new InputfieldFieldset());
		$lists->label = $this->_('Black and White Lists');
		$lists->description = $this->_('Whitelist will override blacklist');
		$lists->notes = $this->_('You can modify the operation of these lists by hooking the checkSender method. See documentation.');
		$lists->columnWidth = 100;
		$inputfields->add($lists);

		/**
		 * Configuration field for black list
		 * One or more email addresses can be added to the black list
		 */
		$f = $this->modules->get('InputfieldTextarea');
		$f_name = 'blackList';
		$f->name = $f_name;
		$f->label = $this->_('Black List');
		$f->description = $this->_('Enter one or more email addresses / domains to block');
		$f->notes = $this->_('One email address or domain name per line');
		$f->columnWidth = 50;
		$f->value = $data[$f_name];
		$lists->add($f);

		/**
		 * Configuration field for white list
		 * One or more email addresses can be added to the white list
		 */
		$f = $this->modules->get('InputfieldTextarea');
		$f_name = 'whiteList';
		$f->name = $f_name;
		$f->label = $this->_('White List');
		$f->description = $this->_('Enter one or more email addresses / domains to allow');
		$f->notes = $this->_('One email address or domain name per line');
		$f->columnWidth = 50;
		$f->value = $data[$f_name];
		$lists->add($f);

		// Spoofing / phishing
		/** @var InputfieldFieldset $hacks */
		$hacks = $this->wire(new InputfieldFieldset());
		$hacks->label = $this->_('Spoofing / Phishing');
		$hacks->description = $this->_('Spoofing and phishing checks');
		$hacks->notes = $this->_('You may wish to consider these if your server does not provide them first');
		$hacks->columnWidth = 100;
		$inputfields->add($hacks);

		/**
		 * Configuration field for spf check
		 */
		$f = $this->modules->get('InputfieldCheckbox');
		$f_name = 'spfCheck';
		$f->name = $f_name;
		$f->label = $this->_('SPF check');
		$f->description = $this->_('Apply SPF check');
		$f->notes = $this->_('This helps prevent spoofing, in case your server has not already done so');
		$f->columnWidth = 33;
		$f->value = $data[$f_name] ?? null;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$hacks->add($f);

		/**
		 * Configuration field for dkim check
		 */
		$f = $this->modules->get('InputfieldCheckbox');
		$f_name = 'dkimCheck';
		$f->name = $f_name;
		$f->label = $this->_('DKIM check');
		$f->description = $this->_('Apply DKIM check');
		$f->notes = $this->_('This helps prevent phishing inserts in case your server has not already done so');
		$f->columnWidth = 33;
		$f->value = $data[$f_name] ?? null;
		$f->checked = ($f->value == 1) ? 'checked' : '';
		$hacks->add($f);

		/**
		 * Configuration field for retention period
		 * After the specified number of days, the files in the 'unknown', 'quarantine' and 'bad' directories will be deleted
		 * Until then, processing will be retried after a change of configuration fields or relevant pages
		 */
		/** @var InputfieldFieldset $retention */
		$retention = $this->wire(new InputfieldFieldset());
		$retention->label = $this->_('Retention periods');
		$retention->description = $this->_('After the specified number of days, the files in the "processed", "unknown", "quarantine" and "bad" directories will be deleted');
		$retention->notes = $this->_('Until the specified number of days have elapsed, unprocessed emails will be resubmitted after a change of configuration fields or relevant pages. **Enter 0 to keep files indefinitely**');
		$retention->columnWidth = 100;
		$inputfields->add($retention);

		// Processed
		$f = $this->modules->get('InputfieldInteger');
		$f_name = 'retentionProcessed';
		$f->name = $f_name;
		$f->label = $this->_('Retention period for processed emails');
		$f->placeholder = 'default = 0';
		$f->notes = $this->_('Processed emails are those that have been successfully linked to a page. You probably want to keep these, in which case leave it at 0.');
		$f->wrapClass('data-lpignore', true); // prevent LastPass from attempting to fill it
		$f->columnWidth = 20;
		$f->value = ($data[$f_name]) ?? 0;
		$retention->add($f);

		// Orphans
		$f = $this->modules->get('InputfieldInteger');
		$f_name = 'retentionOrphans';
		$f->name = $f_name;
		$f->label = $this->_('Retention period for orphaned processed emails');
		$f->notes = $this->_('Orphaned emails are those that have been processed but do not (any longer) have a linked page');
		$f->placeholder = 'default = 0';
		$f->columnWidth = 20;
		$f->value = ($data[$f_name]) ?? 0;
		$retention->add($f);

		// Unknown
		$f = $this->modules->get('InputfieldInteger');
		$f_name = 'retentionUnknown';
		$f->name = $f_name;
		$f->label = $this->_('Retention period for emails addressed to unknown recipients');
		$f->placeholder = 'default = 0';
		$f->wrapClass('data-lpignore', true);
		$f->columnWidth = 20;
		$f->value = ($data[$f_name]) ?? 0;
		$retention->add($f);

		// Quarantine
		$f = $this->modules->get('InputfieldInteger');
		$f_name = 'retentionQuarantine';
		$f->name = $f_name;
		$f->label = $this->_('Retention period for quarantined emails (suspected spoofs etc.)');
		$f->placeholder = 'default = 0';
		$f->wrapClass('data-lpignore', true);
		$f->columnWidth = 20;
		$f->value = ($data[$f_name]) ?? 0;
		$retention->add($f);

		// Bad
		$f = $this->modules->get('InputfieldInteger');
		$f_name = 'retentionBad';
		$f->name = $f_name;
		$f->label = $this->_('Retention period for unreadable emails');
		$f->placeholder = 'default = 0';
		$f->wrapClass('data-lpignore', true);
		$f->columnWidth = 20;
		$f->value = ($data[$f_name]) ?? 0;
		$retention->add($f);

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
			$f->label = $this->_('Report page');
			$f->description = $this->_('Assuming ListerPro is installed, select the lister (if any) to use for the report');
			$f->notes = $this->_('There will also be a summary admin page at.');
			$f->columnWidth = 100;
			foreach($this->pages->find("template=admin, process=ProcessPageListerPro") as $lister) {
				$f->addOption($lister->id, $lister->title);
			}
			$f->value = $data[$f_name];
			$reporting->add($f);
		}
	}


}