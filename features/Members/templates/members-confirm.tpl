<form class="members-confirm[[classes]]" method="post" action="[[/nino/dir]]/.members/confirm">
	<p class="members-confirm__intro">[[/feature/members/confirm/intro]]</p>
	<p class="members-confirm__name">[[name]]</p>
	<input type="hidden" name="token" value="[[token]]">
	[csrf]
	[[state]]
	<button type="submit" class="nino-btn nino-btn--primary">[[/feature/members/confirm/button]]</button>
</form>
