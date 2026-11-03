<form class="review-form" id="[[id]]" method="post" action="[[/nino/dir]]/.review/comment">
	<input type="hidden" name="file" value="[[file]]">
	<input type="hidden" name="return" value="[[return]]">
	<input type="hidden" name="parent" value="">
	[csrf]
	<p class="review-form__field">
		<label for="[[id]]-at" class="review-form__label">[[/feature/review/form/at]]</label>
		<input type="text" id="[[id]]-at" name="at" class="review-form__at" value="" size="8" maxlength="12" autocomplete="off" placeholder="0:00">
		<button type="button" class="review-form__now" hidden>[[/feature/review/form/now]]</button>
	</p>
	<p class="review-form__field">
		<label for="[[id]]-text" class="review-form__label">[[/feature/review/form/text]]</label>
		<textarea id="[[id]]-text" name="text" class="review-form__text" rows="4" maxlength="[[length]]" required></textarea>
	</p>
	[[state]]
	<button type="submit" class="nino-btn nino-btn--primary">[[/feature/review/form/button]]</button>
</form>
