<section class="crm-login crm-card" aria-labelledby="crm-login-heading">
		<h3 class="crm-login__title crm-section__title crm-section__title--sub" id="crm-login-heading">[[icon:mail]]<span>[[/project/crm-portal/data/login-title]]</span></h3>
		<p class="crm-login__text">[[/project/crm-portal/data/login-now]] <strong class="crm-login__address">[[address]]</strong></p>
		[[state]]
		[[step]]
		<form class="crm-login__devices" method="post" action="[[dir]]/.crm/geraete/abmelden">
			[csrf]
			<p class="crm-login__text crm-login__hint">[[/project/crm-portal/data/devices-text]]</p>
			<p class="crm-data__actions"><button type="submit" class="crm-btn crm-btn--secondary crm-btn--small">[[icon:log-out]]<span>[[/project/crm-portal/data/devices-button]]</span></button></p>
		</form>
	</section>
