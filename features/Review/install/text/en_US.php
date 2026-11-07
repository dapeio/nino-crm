<?php
declare(strict_types=1);

// The words the site says - merged into the project's own text/en_US.php on every activation, add-only: a key
// the project already has stays. From then on an editor keeps them current in the Text panel and never opens a
// feature directory. The panel's own words are a different set, in features/Review/text/.
return [

	// The list of the files, [review-player] without a file chosen
	'[[/feature/review/list/empty]]'			=> 'There are no audio or video files for your group yet.',
	'[[/feature/review/kind/audio]]'			=> 'Audio',
	'[[/feature/review/kind/video]]'			=> 'Video',

	// The player of one file
	'[[/feature/review/player/back]]'			=> 'Back to the files',
	'[[/feature/review/player/comments]]'		=> 'Comments',
	'[[/feature/review/player/none]]'			=> 'There are no comments on this file yet.',

	// A comment, and who wrote it
	'[[/feature/review/comment/owner]]'			=> 'Owner',
	'[[/feature/review/comment/member]]'		=> 'A member',
	'[[/feature/review/comment/gone]]'			=> 'A member who is gone',
	'[[/feature/review/comment/done]]'			=> 'Done',
	'[[/feature/review/comment/reply]]'			=> 'Answer',
	'[[/feature/review/comment/play]]'			=> 'Play from here',

	// The form
	'[[/feature/review/form/at]]'				=> 'Time in the track (m:ss, may stay empty)',
	'[[/feature/review/form/follow]]'			=> 'Update the time',
	'[[/feature/review/form/text]]'				=> 'Your comment',
	'[[/feature/review/form/button]]'			=> 'Send',

	// What the form says above its button: the states of ?review-state=
	'[[/feature/review/state/sent]]'			=> 'Your comment is saved.',
	'[[/feature/review/state/long]]'			=> 'Your comment is too long. Please shorten it.',
	'[[/feature/review/state/limit]]'			=> 'Too many comments. Please try again in an hour.',
	'[[/feature/review/state/expired]]'			=> 'Your session has run out. Please write your comment again.',
	'[[/feature/review/state/invalid]]'			=> 'That did not work: the file is not there for you, or the time or the text is not right.',

	// What the script says when it prepares an answer
	'[[/feature/review/script/answering]]'		=> 'Answer to %s',
	'[[/feature/review/script/cancel]]'			=> 'Cancel',

	// The mail to the owner when a member has written a comment, and the mail to a member or a group about a comment of the owner's
	'[[/feature/review/mail/comment-subject]]'	=> 'A member wrote a comment',
	'[[/feature/review/mail/comment-title]]'	=> 'A new comment',
	'[[/feature/review/mail/comment-intro]]'	=> 'A member has written a comment:',
	'[[/feature/review/mail/comment-button]]'	=> 'Open the panel Review',
	'[[/feature/review/mail/comment-about-subject]]'	=> 'A member wrote a comment: [[about]]',
	'[[/feature/review/mail/comment-button-card]]'	=> 'Open the project card',
	'[[/feature/review/mail/label-time]]'		=> 'Time in the track:',
	'[[/feature/review/mail/label-by]]'			=> 'Written by:',
	'[[/feature/review/mail/no-time]]'			=> 'none',
	'[[/feature/review/mail/answer-subject]]'	=> 'An answer to your comment',
	'[[/feature/review/mail/answer-title]]'		=> 'A new comment at',
	'[[/feature/review/mail/answer-greeting]]'	=> 'Hello',
	'[[/feature/review/mail/answer-intro]]'		=> 'The owner has answered your comment on the file',
	'[[/feature/review/mail/note-subject]]'		=> 'A new comment on a file of your group',
	'[[/feature/review/mail/note-intro]]'		=> 'The owner has written a comment on the file',
	'[[/feature/review/mail/answer-button]]'	=> 'Open the website',
	'[[/feature/review/mail/answer-notice]]'	=> 'To read it, sign in with a link that is sent to your email address.',
	'[[/feature/review/mail/answer-notice-code]]'	=> 'To read it, sign in: the code for the sign-in is sent to your email address.',
	'[[/feature/review/mail/closing]]'			=> 'Kind regards',
];
