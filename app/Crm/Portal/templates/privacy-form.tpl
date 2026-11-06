<form class="crm-privacy__form" method="post" action="[[dir]]/.crm/datenschutz">
			[csrf]
			[[weiter]]
			<input type="hidden" name="shown" value="[[hash]]">
			<p class="crm-privacy__field"><label class="crm-privacy__check crm-check" for="crm-privacy-agree"><input type="checkbox" id="crm-privacy-agree" name="agree" value="1" required><span>[[/project/crm-portal/privacy/box]]</span></label></p>
			<p class="crm-privacy__actions"><button type="submit" class="crm-btn crm-btn--primary">[[icon:check]]<span>[[/project/crm-portal/privacy/button]]</span></button></p>
		</form>
