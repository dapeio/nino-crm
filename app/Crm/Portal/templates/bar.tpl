<header class="crm-bar">
	<div class="crm-bar__inner">
		<a class="crm-bar__brand" href="[[dir]]/">[logo alt=""]<span class="crm-bar__name" aria-hidden="true">[[site]]</span><h1 class="nino-sr-only">[[prefix]][[/_nino/webpage[[/nino/http/response/uri]]/title]]</h1></a>
		<a class="crm-account" href="[[dir]]/portal/meine-daten"[[current]]><span class="crm-account__avatar" aria-hidden="true">[[initials]]</span><span class="crm-account__name">[[name]]</span></a>
		<form class="crm-account__out" method="post" action="[[dir]]/.members/logout">
			[csrf]
			<button type="submit" class="crm-account__logout">[[icon:log-out]]<span class="nino-sr-only">[[/feature/members/logout/label]]</span></button>
		</form>
	</div>
</header>
