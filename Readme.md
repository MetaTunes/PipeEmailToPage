Points to note:

* The file 'emailpipe.php' is the main script that processes the email and creates the page. It needs to be set with execute permissions (755).
* All ProcessWire files need to have the line separator set to 'LF' not CRLF' (Windows default). 
** This can be set in Php Storm by going to 'File' -> 'File Properties' -> 'Line Separators' -> 'LF'.
* Depending on your hosting service, spam filters may operate before the email is processed. For example, Krystal uses SpamExperts.

## Installation
1. Copy the 'PipeEmailToPage' folder to your site/modules directory or install from the ProcessWire modules library.
2. (Not in the modules library yet).
3. Install the 'WireMailSmtp' module if you don't already have it installed.
4. Install the zbateson/mail-mime-parser library by running `composer require zbateson/mail-mime-parser` in the terminal.

## Configuration
1. Define a template for the parent pages which will hold the email pages (e.g. 'MailCategory'). This should have an
email field (e.g. 'email') as a minimum.
2. Define your receiving email addresses in pages with that template.
3. Define a template for pages to hold the received emails (which will be children of the above) - e.g. 'MailReceived'.
4. Set the above in the module configuration.
5. (Define whitelists and blacklists - not yet implemented).
6. By default, emails from all senders will be accepted (subject to whitelists and blacklists). If you want to
apply special handling of sender, define a hook after PipeEmailToPage::checkSender
   * You need to hook this method in your init.php file to return any result other than true
   * Otherwise all senders will be accepted
   * In your hook, retrieve `$from` as `$event->arguments(0)` and set `$event->return` to false if the sender is not valid