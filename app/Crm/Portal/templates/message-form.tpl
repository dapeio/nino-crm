<form class="crm-message__dismiss" method="post" action="[[dir]]/.crm/nachricht/weg">
		[csrf]
		<input type="hidden" name="sha256" value="[[sha256]]">
		<input type="hidden" name="weiter" value="[[weiter]]">
		<button type="submit" class="crm-message__close" aria-label="[[/project/crm-portal/message/dismiss]]" title="[[/project/crm-portal/message/dismiss]]">[[icon:x]]</button>
	</form>
