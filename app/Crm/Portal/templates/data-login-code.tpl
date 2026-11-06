<form class="crm-login__form crm-login__form--code" method="post" action="[[dir]]/.crm/anmeldeadresse/code">
			[csrf]
			<p class="crm-login__sent">[[/project/crm-portal/data/login-sent]] <strong class="crm-login__address">[[address]]</strong></p>
			<p class="crm-field[[invalid]]">
				<label class="crm-field__label" for="crm-login-code">[[/project/crm-portal/data/login-code]]</label>
				<input class="crm-data__input crm-login__code crm-field__input nino-form-input" type="text" id="crm-login-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" autofocus required aria-describedby="crm-login-code-hint"[[described]]>
				<span class="crm-field__hint" id="crm-login-code-hint">[[/project/crm-portal/data/login-code-hint]]</span>
			</p>
			<p class="crm-data__actions"><button type="submit" class="crm-btn crm-btn--primary">[[icon:check]]<span>[[/project/crm-portal/data/login-confirm]]</span></button><a class="crm-btn crm-btn--ghost" href="[[dir]]/portal/meine-daten">[[/project/crm-portal/data/login-cancel]]</a></p>
		</form>
