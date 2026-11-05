<?php
declare(strict_types=1);

// The panel's own words, one file per interface language (see Admin::text()): merged into the workbench's fills while
// the feature is active, never into the project's text files. The site's words are the other set, in
// features/Files/install/text/
return [

	// The rail entry, named by Admin::nav() - and the name the head over the panel's screen shows, which is why the
	// screen draws no heading of its own
	'[[/_admin/files/nav]]'							=> 'Files',

	// The Dashboard's tile (Admin::summary())
	'[[/_admin/files/label/count]]'					=> 'Files',

	// The units of a size, and the sign the decimals are separated by
	'[[/_admin/files/unit/byte]]'					=> 'B',
	'[[/_admin/files/unit/kilobyte]]'				=> 'KB',
	'[[/_admin/files/unit/megabyte]]'				=> 'MB',
	'[[/_admin/files/unit/gigabyte]]'				=> 'GB',
	'[[/_admin/files/unit/separator]]'				=> '.',

	// What the screen says of itself and of the limits it works within
	'[[/_admin/files/hint/intro]]'					=> 'Folders and files for the groups of the Members feature. A member sees the folders of their group and downloads what is visible to them; in the folder you mark as the upload folder, a member can upload.',
	'[[/_admin/files/hint/limits]]'					=> 'The server takes %s in one request. A file is cut into chunks of %s; the largest file is %s. A limit of the web server (client_max_body_size, for one) is not among them: an upload finds it itself, by halving its chunks when the server refuses them with 413.',
	'[[/_admin/files/hint/quota]]'					=> 'The group uses %s of %s.',
	'[[/_admin/files/hint/backup]]'					=> 'The daily backup carries the index of the files, not the files themselves - they lie in data/files/ and are backed up with the server.',
	'[[/_admin/files/hint/nogroup]]'				=> 'The Members feature has no group yet. Give a member a group in the panel Members, then add folders for it here.',
	'[[/_admin/files/label/no-limit]]'				=> 'any size',

	// The group and the folders
	'[[/_admin/files/label/group]]'					=> 'Group',
	'[[/_admin/files/label/folders]]'				=> 'Folders',
	'[[/_admin/files/label/folder-new]]'			=> 'New folder',
	'[[/_admin/files/label/name]]'					=> 'Name',
	'[[/_admin/files/label/add]]'					=> 'Add folder',
	'[[/_admin/files/label/open]]'					=> 'Open',
	'[[/_admin/files/label/rename]]'				=> 'Rename',
	'[[/_admin/files/label/mark]]'					=> 'Members upload here',
	'[[/_admin/files/label/unmark]]'				=> 'Stop members uploading here',
	'[[/_admin/files/label/save]]'					=> 'Save',
	'[[/_admin/files/label/cancel]]'				=> 'Cancel',
	'[[/_admin/files/label/delete]]'				=> 'Delete',
	'[[/_admin/files/label/count-file]]'			=> '%s file',
	'[[/_admin/files/label/count-files]]'			=> '%s files',
	'[[/_admin/files/state/upload]]'				=> 'Upload folder',
	'[[/_admin/files/empty/folders]]'				=> 'There are no folders for this group yet.',
	'[[/_admin/files/title/folder]]'				=> 'Delete the folder',
	'[[/_admin/files/confirm/folder]]'				=> 'Delete the folder %s?',
	'[[/_admin/files/confirm/folder-file]]'			=> 'Delete the folder %s? Its file is deleted with it, for good.',
	'[[/_admin/files/confirm/folder-files]]'		=> 'Delete the folder %s? Its %s files are deleted with it, for good.',
	'[[/_admin/files/confirm/discard]]'				=> 'What you typed is not saved. Discard it?',
	'[[/_admin/files/error/name]]'					=> 'Enter a name for the folder.',
	'[[/_admin/files/error/folder-add]]'			=> 'The folder could not be added.',
	'[[/_admin/files/error/folder-save]]'			=> 'The folder could not be renamed.',
	'[[/_admin/files/error/folder-delete]]'			=> 'The folder could not be deleted.',
	'[[/_admin/files/error/target]]'				=> 'The mark could not be changed.',

	// The files of the open folder
	'[[/_admin/files/label/files-in]]'				=> 'Files in %s',
	'[[/_admin/files/empty/files]]'					=> 'There are no files in this folder yet.',
	'[[/_admin/files/label/by-owner]]'				=> 'Owner',
	'[[/_admin/files/label/by-gone]]'				=> 'A member who is gone',
	'[[/_admin/files/label/visible]]'				=> 'Visible to the members',
	'[[/_admin/files/label/download]]'				=> 'Download',
	'[[/_admin/files/label/move]]'					=> 'Move to',
	'[[/_admin/files/title/file]]'					=> 'Delete the file',
	'[[/_admin/files/confirm/file]]'				=> 'Delete %s? The file is gone for good.',
	'[[/_admin/files/error/save]]'					=> 'The file could not be changed.',
	'[[/_admin/files/error/delete]]'				=> 'The file could not be deleted.',

	// The owner's upload
	'[[/_admin/files/label/upload-to]]'				=> 'Upload into %s',
	'[[/_admin/files/label/choose]]'				=> 'Files',
	'[[/_admin/files/label/upload-visible]]'		=> 'Visible to the members',
	'[[/_admin/files/hint/upload-visible]]'			=> 'Off, the files stay hidden until you make them visible.',
	'[[/_admin/files/label/notify]]'				=> 'Inform the members by e-mail',
	'[[/_admin/files/hint/notify]]'				=> 'Applies to every upload and every visibility switch of this screen while it is ticked. Preset from the setting of the feature.',
	'[[/_admin/files/label/upload]]'				=> 'Upload',
	'[[/_admin/files/label/waiting]]'				=> 'Waiting',
	'[[/_admin/files/label/sending]]'				=> 'Uploading',
	'[[/_admin/files/label/smaller]]'				=> 'The server does not take requests of this size. The file is sent again in smaller parts.',
	'[[/_admin/files/label/done]]'					=> 'Uploaded',
	'[[/_admin/files/msg/uploaded-file]]'			=> 'The file is uploaded.',
	'[[/_admin/files/msg/uploaded]]'				=> '%s files uploaded.',
	'[[/_admin/files/error/choose]]'				=> 'Choose the files to upload first.',
	'[[/_admin/files/error/empty]]'					=> '%s is empty.',
	'[[/_admin/files/error/toolarge]]'				=> '%s is larger than the largest file (%s).',
	'[[/_admin/files/error/upload]]'				=> 'The file could not be uploaded.',
	'[[/_admin/files/error/floor]]'					=> 'The server takes no request of even this small size. Ask your host how large a request may be.',

	// What the panel says of a list that could not be loaded: the key Nino.adminUi.panel() takes for a failed load
	'[[/_admin/files/error/load]]'					=> 'The files could not be loaded.',

	// The sentences of the codes the panel and the route answer with
	'[[/_admin/error/files_group]]'					=> 'The Members feature has no such group.',
	'[[/_admin/error/files_name]]'					=> 'The name is empty.',
	'[[/_admin/error/files_long]]'					=> 'The name may have up to %s characters.',
	'[[/_admin/error/files_unknown]]'				=> 'There is no such folder or file (any more).',
	'[[/_admin/error/files_target]]'				=> 'A file can only be moved to another folder of the same group.',
	'[[/_admin/error/files_folder]]'				=> 'There is no such folder (any more).',
	'[[/_admin/error/files_type]]'					=> 'This kind of file is not accepted: the extension is not in the list of the types, or the bytes are not what the name says.',
	'[[/_admin/error/files_size]]'					=> 'The file may be up to %s MB.',
	'[[/_admin/error/files_quota]]'					=> 'The group may hold up to %s MB; the file would take it over.',
	'[[/_admin/error/files_chunk]]'					=> 'The upload was interrupted or did not arrive whole. Try again.',
	'[[/_admin/error/files_limit]]'					=> 'Too many requests. Try again in an hour.',
	'[[/_admin/error/files_write]]'					=> 'The file could not be stored on the server.',
];
