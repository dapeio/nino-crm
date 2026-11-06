/**
 *	Crm - Portal
 *	portal.js		The one script of the customer portal, bundled into the project's own /.cache/script.js by \Crm\Crm::init() (the module adds it
 *							with \Nino\Html::addAsset(), as it adds portal.css). It wires what the pages cannot say in markup alone, and every page works without
 *							it: the tab of a project page is written into the address when it is switched (?tab=angebot, dateien, belege, uebersicht), so that a reload and
 *							the back button keep it (the tabs are links to the sections, and once the kernel's script has made them tabs a click on one does not
 *							leave the page); the open tab is scrolled into the row of tabs where the row is wider than the window; a message the address carries (?state=..., ?sign-state=...) is taken out of the address once the page has shown it,
 *							so that a reload does not say it again; the card or the row an anchor names (#angebot-<id>, #beleg-<id>), or the row of the document
 *							Sign has just brought back (data-sign-state), is unfolded and brought into view, also when it stands in a tab the kernel's script has
 *							hidden; the field of Meine Daten the server refused gets the focus; the drop zone of the upload says that a file hangs over it; and the select of the versions over a player sends its form when the choice changes - at once for the mouse and touch, on Enter or on leaving for the keyboard - and gets the focus back after the page is loaded (the button that does it without the script is taken away). The pills over the list of documents are shown and filter its rows (a row that does not fit is hidden, the number over the list says how many are shown; the pill the server pressed is the one that applies at first, and a row that is hidden is not unfolded by an anchor); a message of the owner is put away by posting the form on its card, and the card goes from the page once the server has answered (it stays where the post fails). No markup is made here, nothing is sent but those two forms (a plain GET, a post to the same site) and nothing is stored.
 */
( function() {

	'use strict';

	const dc = document;

	// The query without the parts that say nothing twice: the message of a form and the document it was about
	function cleanQuery( params, dropTab ) {
		params.delete( 'state' );
		params.delete( 'field' );
		params.delete( 'why' );
		params.delete( 'beleg' );
		params.delete( 'sign' );
		params.delete( 'sign-state' );
		if( dropTab === true )
			params.delete( 'tab' );
		return params;
	}

	function replaceAddress( params, hash ) {
		const query = params.toString();
		history.replaceState( history.state, '', location.pathname + ( query === '' ? '' : '?' + query ) + hash );
	}

	// A message the address carries is shown once: the page has it, the address loses it
	function forgetState() {
		const params = new URLSearchParams( location.search );
		if( params.has( 'state' ) === false && params.has( 'beleg' ) === false && params.has( 'sign-state' ) === false )
			return;
		replaceAddress( cleanQuery( params, false ), location.hash );
	}

	// The tab of a project page follows into the address: the kernel's script switches the panels, this writes ?tab=
	function followTabs() {
		const tabs = Array.from( dc.querySelectorAll( '.crm-project__tab[data-tab]' ) );
		if( tabs.length === 0 || typeof MutationObserver === 'undefined' )
			return;
		const write = function( tab ) {
			const params = cleanQuery( new URLSearchParams( location.search ), true );
			params.set( 'tab', tab.getAttribute( 'data-tab' ) );
			if( tab.getAttribute( 'data-tab' ) !== 'dateien' )
				params.delete( 'review' );
			replaceAddress( params, '' );
		};
		const observer = new MutationObserver( function( records ) {
			for( const record of records ) {
				const tab = record.target;
				if( tab instanceof HTMLElement && tab.classList.contains( 'nino-is-active' ) && tab.getAttribute( 'aria-selected' ) === 'true' && tab.getAttribute( 'data-crm-written' ) !== '1' ) {
					tabs.forEach( function( other ) { other.removeAttribute( 'data-crm-written' ); } );
					tab.setAttribute( 'data-crm-written', '1' );
					if( tab.getAttribute( 'data-crm-initial' ) !== '1' )
						write( tab );
				}
			}
		} );
		tabs.forEach( function( tab ) {
			if( tab.classList.contains( 'nino-is-active' ) ) {
				tab.setAttribute( 'data-crm-initial', '1' );
				tab.setAttribute( 'data-crm-written', '1' );
			}
			observer.observe( tab, { attributes: true, attributeFilter: [ 'class', 'aria-selected' ] } );
		} );
		tabs.forEach( function( tab ) {
			// A tab is a link to its section until the kernel's script has made it one: then the click switches the panel and goes nowhere
			tab.addEventListener( 'click', function( event ) {
				if( tab.getAttribute( 'role' ) === 'tab' )
					event.preventDefault();
				tab.removeAttribute( 'data-crm-initial' );
			} );
			tab.addEventListener( 'keydown', function() { tabs.forEach( function( other ) { other.removeAttribute( 'data-crm-initial' ); } ); } );
		} );
	}

	// The row of tabs scrolls inside its box where it is wider than the window: the open tab is brought into the box (the box scrolls, the page does not), so that
	// a person who opens the page sees which tab is open
	function showOpenTab() {
		const nav = dc.querySelector( '.crm-project__nav' );
		const open = nav === null ? null : nav.querySelector( '.nino-is-active' );
		if( open === null )
			return;
		const box = nav.getBoundingClientRect();
		const tab = open.getBoundingClientRect();
		if( tab.right > box.right )
			nav.scrollLeft += tab.right - box.right;
		else if( tab.left < box.left )
			nav.scrollLeft -= box.left - tab.left;
	}

	// What an anchor names is unfolded and brought into view - after the kernel's script has hidden the other tabs, so the place is the final one
	function revealTarget() {
		const hash = location.hash;
		let target = null;
		if( hash.length >= 2 ) {
			try { target = dc.getElementById( decodeURIComponent( hash.slice( 1 ) ) ); } catch( e ) { target = null; }
		}
		// No anchor: the row of the document Sign has just brought back, which the server marked
		else if( hash.length === 0 )
			target = dc.querySelector( '[data-sign-state]' );
		// A row the filter hides is not unfolded
		const row = target === null ? null : target.closest( '.crm-documents__row' );
		if( target === null || ( row !== null && row.hidden === true ) )
			return;
		let node = target;
		while( node !== null ) {
			if( node.tagName === 'DETAILS' )
				node.open = true;
			node = node.parentElement;
		}
		// A row of a list folds by a details of its own, inside the row the anchor names
		const fold = target.querySelector( ':scope > details' );
		if( fold !== null )
			fold.open = true;
		window.requestAnimationFrame( function() {
			target.scrollIntoView( { block: 'start', behavior: 'auto' } );
			if( target.hasAttribute( 'tabindex' ) === false )
				target.setAttribute( 'tabindex', '-1' );
			// After a change of version the address names the form: the focus goes back to its select, where the person left it
			( target.id === 'crm-versions' && target.querySelector( 'select' ) !== null ? target.querySelector( 'select' ) : target ).focus( { preventScroll: true } );
		} );
	}

	// The drop zone of the upload says that a file hangs over it
	function watchDrop() {
		dc.querySelectorAll( '.files-upload.crm-upload' ).forEach( function( form ) {
			let depth = 0;
			form.addEventListener( 'dragenter', function() { depth++; form.classList.add( 'crm-upload--over' ); } );
			form.addEventListener( 'dragleave', function() { depth = Math.max( 0, depth - 1 ); if( depth === 0 ) form.classList.remove( 'crm-upload--over' ); } );
			form.addEventListener( 'drop', function() { depth = 0; form.classList.remove( 'crm-upload--over' ); } );
		} );
	}

	// The select of the versions over a player asks the page for the version chosen, with a plain GET: the button that does it without the script goes. A choice
	// made with the mouse or by touch sends the form at once; a choice made with the keyboard (arrows, Home, End, PageUp, PageDown, a letter) only marks it, and
	// the form is sent on Enter or when the select is left with another version than the page has chosen - every arrow would otherwise load a page and drop the
	// focus
	function watchVersions() {
		dc.querySelectorAll( '.crm-versions' ).forEach( function( form ) {
			const select = form.querySelector( 'select' );
			const button = form.querySelector( '.crm-versions__go' );
			if( select === null )
				return;
			if( button !== null )
				button.hidden = true;
			let keyboard = false;
			const changed = function() {
				const option = select.options[ select.selectedIndex ];
				return option !== undefined && option.defaultSelected !== true;
			};
			const send = function() {
				if( typeof form.requestSubmit === 'function' )
					form.requestSubmit();
				else
					form.submit();
			};
			[ 'pointerdown', 'mousedown', 'touchstart' ].forEach( function( name ) { select.addEventListener( name, function() { keyboard = false; } ); } );
			select.addEventListener( 'keydown', function( event ) {
				if( event.key === 'Enter' ) {
					keyboard = false;
					if( changed() ) {
						event.preventDefault();
						send();
					}
					return;
				}
				if( [ 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown' ].indexOf( event.key ) !== -1 || ( event.key.length === 1 && event.ctrlKey !== true && event.metaKey !== true && event.altKey !== true ) )
					keyboard = true;
			} );
			select.addEventListener( 'change', function() {
				if( keyboard === false )
					send();
			} );
			select.addEventListener( 'blur', function() {
				const was = keyboard;
				keyboard = false;
				if( was === true && changed() )
					send();
			} );
		} );
	}

	// A field the server refused is where the person goes on: the form of Meine Daten marks it, and the focus is put there
	function focusRefused() {
		const field = dc.querySelector( '.crm-data__form [aria-invalid="true"]' );
		if( field !== null )
			field.focus();
	}

	// The pills over the list of documents: shown once the script is here, each one a filter on the rows (by their kind, or by the mark that they ask for something). The pill
	// the server pressed is the one that applies first; the number over the list tells how many rows are shown when it is not all of them
	function watchFilter() {
		const group = dc.querySelector( '.crm-filter' );
		if( group === null )
			return;
		const pills = Array.from( group.querySelectorAll( '.crm-filter__pill' ) );
		const rows = Array.from( dc.querySelectorAll( '.crm-documents__row' ) );
		const count = dc.querySelector( '.crm-documents__count' );
		const whole = count === null ? '' : count.textContent;
		const apply = function( pill ) {
			const name = pill.getAttribute( 'data-filter' );
			const kinds = ( pill.getAttribute( 'data-kinds' ) || '' ).split( ' ' );
			let shown = 0;
			pills.forEach( function( other ) { other.setAttribute( 'aria-pressed', other === pill ? 'true' : 'false' ); } );
			rows.forEach( function( row ) {
				const fits = name === 'all' || ( name === 'due' ? row.getAttribute( 'data-due' ) === '1' : kinds.indexOf( row.getAttribute( 'data-kind' ) ) !== -1 );
				row.hidden = fits === false;
				// A row that is hidden is folded: the server opens the one the address names, and it is not to stand open when the filter shows it
				if( fits === false )
					row.querySelectorAll( 'details' ).forEach( function( fold ) { fold.open = false; } );
				shown += fits ? 1 : 0;
			} );
			if( count !== null )
				count.textContent = shown === rows.length ? whole : ( count.getAttribute( 'data-shown' ) || '' ).replace( '{shown}', String( shown ) ).replace( '{total}', String( rows.length ) );
		};
		pills.forEach( function( pill ) { pill.addEventListener( 'click', function() { apply( pill ); } ); } );
		const chosen = pills.find( function( pill ) { return pill.getAttribute( 'aria-pressed' ) === 'true'; } );
		if( chosen !== undefined )
			apply( chosen );
		group.hidden = false;
	}

	// The form on the card of a message: posted in the background, and the card goes once the server has answered; where the post fails (the answer is no page, or it
	// says the token ran out) the card stays and the button is free again. A form that posts to another site is left to the browser
	function watchMessages() {
		dc.querySelectorAll( 'form.crm-message__dismiss' ).forEach( function( form ) {
			form.addEventListener( 'submit', function( event ) {
				let own = false;
				try { own = new URL( form.action, location.href ).origin === location.origin; } catch( e ) { own = false; }
				if( own === false || typeof fetch !== 'function' )
					return;
				event.preventDefault();
				const card = form.closest( '.crm-message' );
				const button = form.querySelector( 'button' );
				const body = new URLSearchParams();
				form.querySelectorAll( 'input[name]' ).forEach( function( input ) { body.append( input.getAttribute( 'name' ), input.value ); } );
				if( button !== null )
					button.disabled = true;
				const failed = function() {
					if( button !== null )
						button.disabled = false;
				};
				fetch( form.action, { method : 'POST', body : body, credentials : 'same-origin' } ).then( function( response ) {
					let refused = true;
					try {
						const answer = new URL( response.url, location.href );
						refused = answer.origin !== location.origin || answer.searchParams.get( 'state' ) === 'expired';
					} catch( e ) { refused = true; }
					if( response.ok !== true || refused === true ) {
						failed();
						return;
					}
					if( card !== null )
						card.remove();
					const title = dc.querySelector( '.crm-head__title' );
					if( title !== null ) {
						title.setAttribute( 'tabindex', '-1' );
						title.focus( { preventScroll: true } );
					}
				} ).catch( failed );
			} );
		} );
	}

	function init() {
		if( dc.querySelector( '.crm-portal' ) === null )
			return;
		focusRefused();
		followTabs();
		showOpenTab();
		window.addEventListener( 'load', showOpenTab );
		watchDrop();
		watchVersions();
		watchFilter();
		watchMessages();
		forgetState();
		revealTarget();
		window.addEventListener( 'hashchange', revealTarget );
	}

	if( dc.readyState === 'loading' )
		dc.addEventListener( 'DOMContentLoaded', init );
	else
		init();

} )();
