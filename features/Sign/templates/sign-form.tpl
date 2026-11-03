<div class="sign-form[[classes]]">
	[[heading]]
	<p class="sign-form__intro">[[/feature/sign/form/intro]]</p>
	<div class="sign-form__document">
		<iframe class="sign-form__frame" src="[[/nino/dir]]/.sign/pdf?[[key]]=[[value]]&amp;kind=shown" title="[[/feature/sign/form/document]]"></iframe>
		<p class="sign-form__open"><a href="[[/nino/dir]]/.sign/pdf?[[key]]=[[value]]&amp;kind=shown" target="_blank" rel="noopener">[[/feature/sign/form/open]]</a></p>
	</div>
	<form class="sign-form__form" method="post" action="[[/nino/dir]]/.sign" data-sign-form>
		<input type="hidden" name="[[key]]" value="[[value]]">
		<input type="hidden" name="return" value="[[return]]">
		<input type="hidden" name="image" value="" data-sign-image>
		[csrf]
		<p class="sign-form__consent">
			<input type="checkbox" id="[[id]]-consent" name="consent" value="1" required>
			<label for="[[id]]-consent">[[/feature/sign/form/consent]]</label>
		</p>
		<div class="sign-pad">
			<div class="sign-pad__draw" data-sign-draw hidden>
				<p class="sign-pad__label" id="[[id]]-pad">[[/feature/sign/form/pad]]</p>
				<canvas class="sign-pad__canvas" width="600" height="200" data-sign-pad aria-labelledby="[[id]]-pad">[[/feature/sign/form/pad-fallback]]</canvas>
				<button type="button" class="nino-btn nino-btn--outline sign-pad__clear" data-sign-clear>[[/feature/sign/form/clear]]</button>
			</div>
			<div class="sign-pad__typed" data-sign-name>
				<label class="sign-pad__label" for="[[id]]-name">[[/feature/sign/form/name]]</label>
				<input type="text" id="[[id]]-name" name="name" class="nino-form-input" maxlength="60" autocomplete="name">
			</div>
		</div>
		[[state]]
		<div class="sign-form__actions">
			<button type="submit" name="action" value="sign" class="nino-btn nino-btn--primary">[[/feature/sign/form/sign]]</button>
			<button type="submit" name="action" value="decline" class="nino-btn nino-btn--outline" formnovalidate>[[/feature/sign/form/decline]]</button>
		</div>
	</form>
</div>
