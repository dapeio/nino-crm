<div class="crm-data[[classes]]">
	<form class="crm-data__form" method="post" action="[[dir]]/.crm/daten">
		[[state]]
		[csrf]
		<div class="crm-data__card crm-card">
			<div class="crm-fields">
				[[fields]]
				<p class="crm-field crm-field--email crm-field--wide">
					<label class="crm-field__label" for="crm-data-email">[[/project/crm-portal/data/field/email]]</label>
					<input class="crm-data__input crm-field__input nino-form-input" type="email" id="crm-data-email" value="[[email]]" readonly aria-describedby="crm-data-email-hint">
					<span class="crm-field__hint" id="crm-data-email-hint">[[/project/crm-portal/data/email-hint]]</span>
				</p>
			</div>
			<p class="crm-data__actions"><button type="submit" class="crm-btn crm-btn--primary">[[icon:check]]<span>[[/project/crm-portal/data/save]]</span></button></p>
		</div>
	</form>
	[[privacy]]
</div>
