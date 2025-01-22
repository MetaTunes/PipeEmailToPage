## About this module
This module is designed to process emails sent to a specific email address and to create pages in ProcessWire from the email content. 
The email addresses are created 'virtually' in the sense that they are not necessarily real email addresses but are held in pages whose templates are linked in the module config.
(But note that real email addresses can be used too).

The module works when emails are piped to a script (emailpipe.php) via a shell (wrapper.sh) which processes the email and creates the page.
The pipe to wrapper.sh needs to be defined in your hosting service's cPanel or similar.
If you define the pipe in the 'Default address' in cPanel, all emails sent to the domain will be processed unless they are specified separately. 
The recipient address will be matched against the email addresses defined in 'parent' pages holding the virtual addresses.
If you define the pipe for a specific email address, only emails sent to that address will be processed (but you can define the same pipe for multiple addresses).

Since a major advantage of the module is that the email addresses can be defined entirely within PW, the 'Default address' method is assumed but not necessary.

The pipe intially copies the email to a file in the 'queue' subdirectory of 'site/assets/emailpipe' directory (attaching sender and recipient addresses from the SMTP envelope as custom headers), 
then the module processes the email and creates the page, after which the file is moved to the 'processed' subdirectory.
If the PW API or database is not available when the pipe script runs, the incoming queue will be processed later via a LazyCron task.

Multiple 'parent templates' can be defined, each with its own structure, provided they contain an email address field.
The received mails will become children of the parent pages which match their recipient address. The received mail template needs to have (at least) an email/text field for the sender address,
a text field for the subject, a textarea field for the body and image and files fields for the attachments. The body field can be defined to be either plain text or html. 
It may also have a text field to store the addressee details. This will show the 'to', 'cc' and 'bcc' addresses (but not the 'bcc's for other recipients). 
HTML in the body is purified using HTMLPurifier before saving in the page.

If the incoming recipient address is not found in the parent pages, then the error will be logged and the email file moved to the 'unknown' subdirectory.

Black and white lists can be defined in the module config. 
Further checking of the email sender is available via a hook. You can hook the PipeEmailToPage::checkSender() in your init.php file to return a different result. Normally, this would be a 'before' hook:
* In your hook, retrieve `$from` as ``$event->arguments(0)``.
* If you want to apply specific processing dependent on the parent page, retrieve the parent page as ``$event->arguments(1)``.
* If you hook before this method and set ```$event->arguments(3)``` to true or false, the white and black lists will be applied afterwards.
* You can supply a custom error message by setting ```$event->arguments(2)```.
* If you hook after this method, you can ignore the white and black lists, apply your own logic
and set $event->return to false if the sender is not valid.
* Rejected email files will result in a log entry and the email file will be moved to the 'quarantine' subdirectory.

Any emails which cannot be opened or processed for some reason will be logged and the email file moved to the 'bad' subdirectory.

The system will attempt to process failed emails if any relevant page, or the module config, is changed.

After the email pages are created, subsequent processing is up to the developer (most probably via a LazyCron task). For example, the parent page may contain email addresses for forwarding or other fields relevant to how the received mail will be processed.
In the case of forwarding, it is suggested to use a checkbox field to keep track of whether the mail has been forwarded.
In other cases, the original email page might be deleted after processing. This might result in an 'orphan' email file unless it is also deleted.



## Points to note:

* The file 'emailpipe.php' is the main script that captures the email. It needs to be set with execute permissions (755).
It is called from wrapper.sh, which is the script that is called by the pipe and also needs to be set with permission 755.
* All ProcessWire files (or at least those called by the pipe) need to have the line separator set to 'LF' not CRLF' (Windows default). 
** This can be set in PhpStorm (for example) by going to 'File' -> 'File Properties' -> 'Line Separators' -> 'LF'.
* Depending on your hosting service, spam filters may operate before the email is processed.
* The module also (optionally) provides its own SPF and DKIM checking (using other open source libraries). 
These have been included via composer within the module directory. It is not recommended to re-run composer as this may overwrite the libraries and create inconsistencies.
In particular, the DKIM library has been modified to fix bugs and improve results.
* The module assumes that your hosting service sets environment variables and allows you access. 
A file 'env.log' is written to site/assets/emailpipe to record the environment variables for you to inspect.
If you do not have access to the environment variables, the module will attempt to use the 'to', 'from' and 'cc' headers in the message, 
but there is no guarantee that this will work - in particular, the 'bcc' addresses will not be available.

## Installation
1. Copy the 'PipeEmailToPage' folder to your site/modules directory or install from the ProcessWire modules library.
2. If you will be wanting to forward emails, install the 'WireMailSmtp' module if you don't already have it installed.

## Configuration
1. Set up the pipe in your hosting service's cPanel or similar to pipe to wrapper.sh. 
In my case the pipe address is public_html/site/modules/PipeEmailToPage/wrapper.sh, but you will need to adjust this to your own server.
Set the permissions of wrapper.sh and emailpipe.php on the server to be 755.
2. Define one or more templates for the parent pages which will hold the email pages (e.g. 'MailCategory'). This should have an
email/text field (e.g. 'email') as a minimum.
3. Define your receiving email addresses in pages with that template.
4. Define a template for pages to hold the received emails (which will be children of the above) - e.g. 'MailReceived'.
5. Set the above (and the related fields) in the module configuration. 
Note that the template and email fields need to be specified otherwise the email will be placed in the 'unknown' subdirectory.
If other configuration fields are omitted then the email will be processed but the information will be lost.
6. Define whitelists and blacklists in the module configuration.
7. By default, emails from all senders will be accepted (subject to whitelists and blacklists). If you want to
apply special handling of sender, define a hook after PipeEmailToPage::checkSender as described above.
8. Allow SPF and DKIM checking if required.
9. The configuration also allows you to set a retention period for each subdirectory. 
During this period, if parent pages are saved with new email addresses, the module will check the 'unknown' directory for emails which were not processed because the parent page did not exist at the time. If the parent page now exists, the email will be processed.
Also, if the black/white lists have changed, the module will reprocess the emails in the 'quarantine' directory.
After the retention period, the emails in the unprocessed queues will be deleted.

## Usage
After you have completed the configuration and set up the pipe in your hosting service, emails sent to the defined (or default) addresses will be processed and pages created in ProcessWire.
It is then up to you, the developer, to decide how to process the emails further. 

For example, you may wish to forward the emails. In this case, you will need to have a field in your parent template to store the forwarding addresses (or to store page references to pages which contain them).
You can then set up a LazyCron task to process the emails and forward them.
It is advisable in this case to also have a checkbox field to record whether the email has been forwarded (or have some other way of avoiding duplicate processing).

Alternatively, you may wish to process the mails to a blog or news page. In this case, you will need to have fields in your parent template to store the relevant information. 
Again you can set up a LazyCron task to process the emails and create the pages. As with forwarding you can set up a checkbox field to record whether the email has been processed.

A 'Process' page - "Mail Received" is provided in setup (or move it where you want) to view the processed emails. 
This allows you to view the emails. You can also delete any unwanted mails. There are 4 tabs - 'Processed', 'Quarantine', 'Unknown' and 'Bad' plus a link to a subsidiary page 'Maintenance/Troubleshooting' (see below). 
In the quarantine tab, you can move emails to be 'Processed' if desired (this will override all spam/spoofing/hacking/sender checks and is irreversible - if it really was undesirable then the only thing you can then do is delete it). 
The 'Maintenance/Troubleshooting' page allows you to see if there are unprocessed emails in the queue. If so, it may be because LazyCron is locked - a button is provided to unlock it.
There is also a tab showing 'orphaned' processed emails - these are emails which have been processed but the page has been trashed/deleted. If desired, you can reprocess these.
You can also attempt to reprocess emails in the 'bad' directory. This is not recommended unless you are sure that the problem has been fixed.

If you have ListerPro, you can also set up a custom Lister view to display the 'processed' emails in a more flexible way, so that you can easily filter them etc.
Note that, if you delete emails in ListerPro, it will only delete the page, not the email file (leaving an 'orphan'), whereas deleting them in the 'Mail Received' page will delete the email file as well.
You can set a link to this from the process page by entering the Lister name at the end of the module configuration.

If you delete linked pages in the API, the email files will not be deleted. You can delete them programmatically from the 'processed' directory if you wish. 
The filename is held as ``$page->meta('filename')`` (remember to get it before you delete the page 😉).

## Versions
Version 1.0.4 has potentially breaking changes from the initial release:
* The pipe has been changed to use a wrapper script which calls the main script. This enables capture of the environment variables.
* Recipient and sender details are added as custom headers to the email file.
* As a consequence, multiple addressees including bcc are handled properly.
* The arguments for ``___checkSender()`` have been changed to permit greater flexibilty in hooking.

In addition
* All messages are retained as files in accordance with defined retention periods.
* The process admin page also displays unknown, quarantined and bad emails, for infomation.
* Emails can be removed out of quarantine and deleted on the Process "Mail Received" page.
* A maintenance/troubleshooting page is provided to unlock LazyCron and reprocess emails.
* DKIM processing has been improved.
