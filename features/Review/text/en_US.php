<?php
declare(strict_types=1);

// The panel's own words, one file per interface language (see Admin::text()): merged into the workbench's fills while
// the feature is active, never into the project's text files. The site's words are the other set, in
// features/Review/install/text/
return [

	// The rail entry, named by Admin::nav() - and the name the head over the panel's screen shows, which is why the
	// screen draws no heading of its own
	'[[/_admin/review/nav]]'						=> 'Review',

	// The Dashboard's tile (Admin::summary())
	'[[/_admin/review/label/open]]'					=> 'Open comments',

	// What the screen says of itself
	'[[/_admin/review/hint/intro]]'					=> 'The audio and video files that members have commented on, by group and folder. Open one to play it, read the comments in the order of the track, answer them, mark them as done or delete them. The comments of a member are open until you mark them.',
	'[[/_admin/review/empty/files]]'				=> 'No comments have been written yet.',
	'[[/_admin/review/label/nogroup]]'				=> 'Without a group',
	'[[/_admin/review/label/count-comment]]'		=> '%s comment',
	'[[/_admin/review/label/count-comments]]'		=> '%s comments',
	'[[/_admin/review/label/count-open]]'			=> '%s open',
	'[[/_admin/review/label/open-file]]'			=> 'Open',
	'[[/_admin/review/kind/audio]]'					=> 'Audio',
	'[[/_admin/review/kind/video]]'					=> 'Video',

	// The comments on one file
	'[[/_admin/review/label/back]]'					=> 'Back to the files',
	'[[/_admin/review/label/comments-on]]'			=> 'Comments on %s',
	'[[/_admin/review/empty/comments]]'				=> 'There are no comments on this file yet.',
	'[[/_admin/review/hint/unplayable]]'			=> 'The bytes of this file are gone, so it cannot be played.',
	'[[/_admin/review/hint/files-permission]]'		=> 'This account may not play files: that needs the permission of the Files panel.',
	'[[/_admin/review/label/owner]]'				=> 'Owner',
	'[[/_admin/review/label/gone]]'					=> 'A member who is gone',
	'[[/_admin/review/state/done]]'					=> 'Done',
	'[[/_admin/review/state/open]]'					=> 'Open',
	'[[/_admin/review/label/reply]]'				=> 'Answer',
	'[[/_admin/review/label/mark-done]]'			=> 'Mark as done',
	'[[/_admin/review/label/reopen]]'				=> 'Open again',
	'[[/_admin/review/label/delete]]'				=> 'Delete',
	'[[/_admin/review/label/cancel]]'				=> 'Cancel',
	'[[/_admin/review/title/comment]]'				=> 'Delete the comment',
	'[[/_admin/review/confirm/comment]]'			=> 'Delete this comment? It is gone for good.',
	'[[/_admin/review/confirm/comment-answer]]'		=> 'Delete this comment? Its answer is deleted with it, for good.',
	'[[/_admin/review/confirm/comment-answers]]'	=> 'Delete this comment? Its %s answers are deleted with it, for good.',
	'[[/_admin/review/confirm/discard]]'			=> 'What you typed is not saved. Discard it?',

	// The owner's comment
	'[[/_admin/review/label/write]]'				=> 'Write a comment',
	'[[/_admin/review/label/answering]]'			=> 'Answer to %s',
	'[[/_admin/review/label/text]]'					=> 'Comment',
	'[[/_admin/review/label/at]]'					=> 'Time in the track (m:ss, may stay empty)',
	'[[/_admin/review/label/now]]'					=> 'At the position of the player',
	'[[/_admin/review/label/send]]'					=> 'Send',
	'[[/_admin/review/error/empty]]'				=> 'Write a comment first.',

	// What the panel says of a request that failed: the key Nino.adminUi.panel() takes for a failed load, and the sentences of the other requests
	'[[/_admin/review/error/load]]'					=> 'The comments could not be loaded.',
	'[[/_admin/review/error/comments]]'				=> 'The comments on the file could not be loaded.',
	'[[/_admin/review/error/answer]]'				=> 'The comment could not be saved.',
	'[[/_admin/review/error/done]]'					=> 'The comment could not be changed.',
	'[[/_admin/review/error/delete]]'				=> 'The comment could not be deleted.',

	// The sentences of the codes the panel answers with
	'[[/_admin/error/review_unknown]]'				=> 'There is no such file or comment (any more).',
	'[[/_admin/error/review_empty]]'					=> 'The comment is empty.',
	'[[/_admin/error/review_long]]'					=> 'The comment may have up to %s characters.',
	'[[/_admin/error/review_time]]'					=> 'This is no time. Write it as m:ss, h:mm:ss or in seconds, or leave it empty.',
];
