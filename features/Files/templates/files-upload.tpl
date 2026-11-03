<form class="files-upload[[classes]]" method="post" action="[[/nino/dir]]/.files/upload" enctype="multipart/form-data" data-files-chunk="[[chunk]]" data-files-max="[[max]]" data-files-multiple="[[multiple]]">
	<input type="hidden" name="folder" value="[[folder]]">
	<input type="hidden" name="count" value="1">
	<input type="hidden" name="return" value="[[return]]">
	[csrf]
	<label for="[[id]]" class="files-upload__label">[[/feature/files/upload/label]]</label>
	<input type="file" id="[[id]]" name="chunk" class="files-upload__input" required>
	<p class="files-upload__hint">[[/feature/files/upload/hint]] [[size]]</p>
	<p class="files-upload__hint">[[/feature/files/upload/types]] [[types]]</p>
	[[state]]
	<button type="submit" class="nino-btn nino-btn--primary">[[/feature/files/upload/button]]</button>
	<ul class="files-upload__list" hidden></ul>
</form>
