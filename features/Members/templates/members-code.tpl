<form class="members-code[[classes]]" method="post" action="[[/nino/dir]]/.members/code">
	<input type="hidden" name="return" value="[[return]]">
	[csrf]
	<p class="members-code__intro">[[/feature/members/code/intro]] <strong class="members-code__email">[[email]]</strong></p>
	<label for="[[id]]" class="members-code__label">[[/feature/members/code/label]]</label>
	<input type="text" id="[[id]]" name="code" class="nino-form-input members-code__input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" autofocus required>
	[[remember]]
	[[state]]
	<button type="submit" class="nino-btn nino-btn--primary">[[/feature/members/code/button]]</button>
	<a href="[[/nino/dir]][[return]]" class="members-code__again">[[/feature/members/code/again]]</a>
</form>
