<form class="crm-order crm-order--form" method="post" action="[[dir]]/.crm/auftrag" data-document="[[id]]">
				[csrf]
				<input type="hidden" name="document" value="[[id]]">
				<input type="hidden" name="shown" value="[[hash]]">
				<input type="hidden" name="from" value="[[from]]">
				<p class="crm-order__field"><label class="crm-order__check" for="crm-order-[[from]]-[[id]]"><input type="checkbox" id="crm-order-[[from]]-[[id]]" name="agree" value="1" required> <span class="crm-order__sentence">[[sentence]]</span></label></p>
				<p class="crm-order__actions"><button type="submit" class="nino-btn nino-btn--primary">[[/project/crm-portal/order/button]]</button></p>
			</form>
