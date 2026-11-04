<form class="crm-privacy__form" method="post" action="[[dir]]/.crm/datenschutz">
	[csrf]
	<input type="hidden" name="shown" value="[[hash]]">
	<p class="crm-privacy__field"><label class="crm-privacy__check" for="crm-privacy-agree"><input type="checkbox" id="crm-privacy-agree" name="agree" value="1" required> <span>[[/project/crm-portal/privacy/box]]</span></label></p>
	<p class="crm-privacy__actions"><button type="submit" class="nino-btn nino-btn--primary">[[/project/crm-portal/privacy/button]]</button></p>
</form>
