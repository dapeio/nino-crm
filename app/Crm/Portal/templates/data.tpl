<form class="crm-data[[classes]]" method="post" action="[[dir]]/.crm/daten">
	[[state]]
	[[privacy]]
	[csrf]
	<div class="crm-data__fields crm-card">
		[[fields]]
		<p class="crm-data__field crm-data__field--account">
			<label class="crm-data__label" for="crm-data-email">[[/project/crm-portal/data/field/email]]</label>
			<input class="crm-data__input nino-form-input" type="email" id="crm-data-email" value="[[email]]" readonly aria-describedby="crm-data-email-hint">
			<span class="crm-data__hint" id="crm-data-email-hint">[[/project/crm-portal/data/email-hint]]</span>
		</p>
	</div>
	<p class="crm-data__actions"><button type="submit" class="crm-btn nino-btn nino-btn--primary">[[/project/crm-portal/data/save]]</button></p>
</form>
