<form class="members-login[[inline]][[classes]]" method="post" action="[[/nino/dir]]/.members/login">
	<input type="hidden" name="return" value="[[return]]">
	[csrf]
	<label for="[[id]]" class="members-login__label[[hidden]]">[[/feature/members/login/label]]</label>
	<input type="email" id="[[id]]" name="email" class="nino-form-input" autocomplete="email" required>
	[[state]]
	<button type="submit" class="nino-btn nino-btn--primary">[[/feature/members/login/button]]</button>
</form>
