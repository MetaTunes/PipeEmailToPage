## About this module
This module is designed to process emails sent to a specific email address and to create pages in ProcessWire from the email content. 
The email addresses are created 'virtually' in the sense that they are not real email addresses but are held in pages whose templates are linked in the module config.
(But note that real email addresses can be used too).

The module works when emails are piped to a script (emailpipe.php) on the server which processes the email and creates the page.
The pipe needs to be defined in your hosting service's cPanel or similar.
If you define the pipe in the 'Default address' in cPanel, all emails sent to the domain will be processed unless they are specified separately. 
The 'to' addresses will be matched against the email addresses defined in 'parent' pages holding the virtual addresses.
If you define the pipe for a specific email address, only emails sent to that address will be processed (but you can define the same pipe for multiple addresses).

Since a major advantage of the module is that the email addresses can be defined entirely within PW, the 'Default address' method is assumed but not necessary.

The pipe intially copies the email to a file in the 'queue' subdirectory of 'site/assets/emailpipe' directory, then the module processes the email and creates the page, after which the file is removed.
If the PW API or database is not available when the pipe script runs, the incoming queue will be processed later via a LazyCron task.

Multiple 'parent templates' can be defined, each with its own structure, provided they contain an email address field.
The received mails will be children of the parent pages which match their 'to' address. The received mail template needs to have (at least) an email field for the 'from' address,
a text field for the subject, a textarea field for the body and image and files fields for the attachments. The body field can be defined to be either plain text or html. 
It may also have a text field to store the addressee details. This will show the 'to', 'cc' and 'bcc' addresses (but not the 'bcc's for other recipients).

If incoming 'to' addresses are not found in the parent pages, then the error will be logged and the email file moved to the 'unknown' subdirectory.
If the email cannot be properly processed because of incomplete definitions in the module config, an error message will be generated and the email file will be moved to the 'unknown' subdirectory.

Black and white lists can be defined in the module config. 
Further checking of the email sender is available via a hook.
* You can hook the PipeEmailToPage::checkSender() in your init.php file to return a different result
* In your hook, retrieve `$from` as ``$event->arguments(0)``
* If you hook before this method set ```$event->arguments(1)``` to true or false, the white and black lists will be applied afterwards
* If you hook after this method, you can ignore the white and black lists and apply you own logic
and set $event->return to false if the sender is not valid
Rejected email files will result in a log entry and the email file will be moved to the 'quarantine' subdirectory.

Any emails wich cannot be opened or processed for some reason will be logged and the email file moved to the 'bad' subdirectory.

After the email pages are created, subsequent processing is up to the developer (most probably via a LazyCron task). For example, the parent page may contain email addresses for forwarding or other fields relavant to how the received mail will be processed.
In the case of forwarding, it is suggested to use a checkbox field to keep track of whether the mail has been forwarded.
In other cases, the original email page might be deleted after processing.



## Points to note:

* The file 'emailpipe.php' is the main script that processes the email and creates the page. It needs to be set with execute permissions (755).
* All ProcessWire files need to have the line separator set to 'LF' not CRLF' (Windows default). 
** This can be set in Php Storm by going to 'File' -> 'File Properties' -> 'Line Separators' -> 'LF'.
* Depending on your hosting service, spam filters may operate before the email is processed. For example, Krystal uses SpamExperts.

## Installation
1. Copy the 'PipeEmailToPage' folder to your site/modules directory or install from the ProcessWire modules library.
2. (Not in the modules library yet).
3. If you will be wanting to forward emails, install the 'WireMailSmtp' module if you don't already have it installed.
4. Install the zbateson/mail-mime-parser library by running `composer require zbateson/mail-mime-parser` in the terminal.

## Configuration
1. Define a template for the parent pages which will hold the email pages (e.g. 'MailCategory'). This should have an
email field (e.g. 'email') as a minimum.
2. Define your receiving email addresses in pages with that template.
3. Define a template for pages to hold the received emails (which will be children of the above) - e.g. 'MailReceived'.
4. Set the above (and the related fields) in the module configuration.
5. Define whitelists and blacklists in the module configuration.
6. By default, emails from all senders will be accepted (subject to whitelists and blacklists). If you want to
apply special handling of sender, define a hook after PipeEmailToPage::checkSender as described above.

## Usage
To be completed.