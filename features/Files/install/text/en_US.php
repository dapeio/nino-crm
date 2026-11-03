<?php
declare(strict_types=1);

// The words the site says - merged into the project's own text/en_US.php on every activation, add-only: a key
// the project already has stays. From then on an editor keeps them current in the Text panel and never opens a
// feature directory. The panel's own words are a different set, in features/Files/text/.
return [

	// The units of a size, and the sign the decimals are separated by: 1.5 MB
	'[[/feature/files/unit/byte]]'			=> 'B',
	'[[/feature/files/unit/kilobyte]]'		=> 'KB',
	'[[/feature/files/unit/megabyte]]'		=> 'MB',
	'[[/feature/files/unit/gigabyte]]'		=> 'GB',
	'[[/feature/files/unit/separator]]'		=> '.',

	// The browser, [files-browser]
	'[[/feature/files/browser/download]]'	=> 'Save',
	'[[/feature/files/browser/empty]]'		=> 'There are no files in this folder yet.',
	'[[/feature/files/browser/none]]'			=> 'There are no folders for your group yet.',

	// The form, [files-upload]
	'[[/feature/files/upload/label]]'		=> 'Choose a file',
	'[[/feature/files/upload/hint]]'		=> 'Largest file:',
	'[[/feature/files/upload/types]]'		=> 'Allowed types:',
	'[[/feature/files/upload/button]]'		=> 'Upload',

	// What the form says above its button: the states of ?files= - and what the script says of a refusal, by the same names
	'[[/feature/files/state/sent]]'			=> 'The file is uploaded.',
	'[[/feature/files/state/folder]]'		=> 'This folder is not there for you any more. Please reload the page.',
	'[[/feature/files/state/type]]'			=> 'This kind of file is not accepted here.',
	'[[/feature/files/state/size]]'			=> 'The file is larger than allowed.',
	'[[/feature/files/state/quota]]'		=> 'The space of your group is used up.',
	'[[/feature/files/state/chunk]]'		=> 'The upload was interrupted or did not arrive whole. Please try again.',
	'[[/feature/files/state/limit]]'		=> 'Too many requests. Please try again in an hour.',
	'[[/feature/files/state/write]]'		=> 'The file could not be stored. Please try again later.',

	// The rest of what the script says of a file in its list
	'[[/feature/files/script/waiting]]'	=> 'Waiting',
	'[[/feature/files/script/sending]]'	=> 'Uploading',
	'[[/feature/files/script/done]]'		=> 'Uploaded',
	'[[/feature/files/script/expired]]'	=> 'Your session has run out. Please reload the page.',
	'[[/feature/files/script/offline]]'	=> 'The connection failed. Please try again.',

	// The mail to the owner when a member has uploaded a file, and the mail to a group when files are there for it
	'[[/feature/files/mail/upload-subject]]'	=> 'A member uploaded a file',
	'[[/feature/files/mail/upload-title]]'		=> 'A new file',
	'[[/feature/files/mail/upload-intro]]'		=> 'A member has uploaded a file:',
	'[[/feature/files/mail/upload-button]]'		=> 'Open the panel Files',
	'[[/feature/files/mail/label-folder]]'		=> 'Folder:',
	'[[/feature/files/mail/label-by]]'				=> 'Uploaded by:',
	'[[/feature/files/mail/new-subject]]'			=> 'New files for you',
	'[[/feature/files/mail/new-title]]'				=> 'New files at',
	'[[/feature/files/mail/new-greeting]]'		=> 'Hello',
	'[[/feature/files/mail/new-intro]]'				=> 'These files are there for you in the folder',
	'[[/feature/files/mail/new-button]]'			=> 'Open the website',
	'[[/feature/files/mail/new-notice]]'			=> 'To download them, sign in with a link that is sent to your email address.',
	'[[/feature/files/mail/closing]]'					=> 'Kind regards',
];
