<form class="crm-versions" id="crm-versions" method="get" action="[[action]]#crm-versions">
	<input type="hidden" name="projekt" value="[[project]]">
	<p class="crm-field crm-versions__field">
		<label class="crm-field__label" for="crm-versions-select">[[/project/crm-portal/files/version]]</label>
		<select class="crm-field__input nino-form-select crm-versions__select" id="crm-versions-select" name="review">
			[[options]]
		</select>
	</p>
	<button class="crm-versions__go crm-btn crm-btn--secondary crm-btn--small" type="submit">[[/project/crm-portal/files/switch]]</button>
</form>
