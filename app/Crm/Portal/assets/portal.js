/**
 *	Crm - Portal
 *	portal.js		The one script of the customer portal, bundled into the project's own /.cache/script.js by \Crm\Crm::init() (the module adds it
 *							with \Nino\Html::addAsset(), as it adds portal.css). It wires what the pages cannot say in markup alone, and every page works without
 *							it: the tab of a project page is written into the address when it is switched (?tab=angebot, dateien, belege, uebersicht), so that a reload and
 *							the back button keep it (the tabs are links to the sections, and once the kernel's script has made them tabs a click on one does not
 *							leave the page); the open tab is scrolled into the row of tabs where the row is wider than the window; a message the address carries (?state=..., ?sign-state=...) is taken out of the address once the page has shown it,
 *							so that a reload does not say it again; the place on the page an address names survives the sign-in (the field return of the login form carries it); the card or the row an anchor names (#angebot-<id>, #beleg-<id>), or the row of the document
 *							Sign has just brought back (data-sign-state), is unfolded and brought into view, also when it stands in a tab the kernel's script has
 *							hidden; the field of Meine Daten the server refused gets the focus; the drop zone of the upload says that a file hangs over it; and the select of the versions over a player sends its form when the choice changes - at once for the mouse and touch, on Enter or on leaving for the keyboard - and gets the focus back after the page is loaded (the button that does it without the script is taken away). The player does not need the page to load again: a click on *Anhören* (or on the name of a file that is played) and the choice of another version ask the route /.crm/player, whose address the column of the player carries (data-crm-player), for the player of that file, and put the answer - the markup the server draws, parsed and taken over as nodes, only where it comes from this origin, from the path of that route and holds a player - in the column; the list marks the row that plays from a template of the page, the address follows (replaceState), and Review binds the new player (Nino.review.init). A version takes over the position of the player it replaces and plays on where that played; the focus stays in the select. Where the answer is anything else, or the network fails, the link is followed and the form is sent the plain way. The pills over the list of documents are shown and filter its rows (a row that does not fit is hidden, the number over the list says how many are shown; the pill the server pressed is the one that applies at first, and a row that is hidden is not unfolded by an anchor); a message of the owner is put away by posting the form on its card, and the card goes from the page once the server has answered (it stays where the post fails). The card that tells how to put the portal on the home screen (data-crm-home, drawn hidden by the server) is shown where the page is no app yet (not display-mode standalone), the window is under 1024px wide and the device has not put it away; the button that puts it away does so for good by writing crm-homescreen to the localStorage of the device (the one thing the script stores, and nothing is sent to the server); where the browser offers the install itself (beforeinstallprompt) the button to install is shown in place of the sentence for Android and asks the browser for it. No markup is made here, nothing is sent but those two forms (a plain GET, a post to the same site) and the request for a player (a GET of the route the column names).
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
	function watchVersions( root ) {
		( root || dc ).querySelectorAll( '.crm-versions' ).forEach( function( form ) {
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
			// With the player at hand the choice is not a page that loads again: the player of that version comes in its place, and the form is sent the plain way only where it cannot
			form.addEventListener( 'submit', function( event ) {
				if( player === null )
					return;
				event.preventDefault();
				player.version( form, select );
			} );
		} );
	}

	// The player of the page that asks the route for a file (see watchPlayer()); null where there is none or the browser cannot do it
	let player = null;

	// The route's answer, or null: a status that is not 200, an answer from another origin or another path (a redirect to the door, say), a type that is no html
	// and a network that fails are all null, and the caller takes the way the page has always had
	function ask( source, file ) {
		const route = new URL( source, location.href );
		return window.fetch( source + '?file=' + encodeURIComponent( file ), { credentials: 'same-origin', headers: { Accept: 'text/html' } } ).then( function( response ) {
			if( response.status !== 200 )
				return null;
			const at = new URL( response.url, location.href );
			if( at.origin !== location.origin || at.pathname !== route.pathname || /^text\/html/i.test( response.headers.get( 'Content-Type' ) || '' ) === false )
				return null;
			return response.text();
		} ).catch( function() { return null; } );
	}

	// The list marks the row of the title that plays: its class, aria-current and the pill, which is cloned from the template of the page and not written here
	function mark( ids ) {
		const template = dc.getElementById( 'crm-files-playing' );
		dc.querySelectorAll( '.crm-files__row' ).forEach( function( row ) {
			const on = ids.indexOf( row.getAttribute( 'data-files-id' ) ) !== -1;
			const pill = row.querySelector( '.crm-files__playing' );
			row.classList.toggle( 'crm-files__row--playing', on );
			if( on )
				row.setAttribute( 'aria-current', 'true' );
			else
				row.removeAttribute( 'aria-current' );
			if( on === false && pill !== null )
				pill.remove();
			if( on === true && pill === null && template !== null ) {
				const text = row.querySelector( '.crm-files__text' );
				if( text !== null )
					text.insertBefore( template.content.cloneNode( true ), row.querySelector( '.crm-files__open' ) );
			}
		} );
	}

	// The player of the page, which takes the files and the versions without the page loading again
	function watchPlayer() {
		const aside = dc.querySelector( 'aside.crm-player[data-crm-player]' );
		if( aside === null || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function' || typeof URL !== 'function' )
			return;
		const source = aside.getAttribute( 'data-crm-player' );
		let latest = 0;

		// The answer is parsed and its nodes are taken over: it is the markup of the route of this origin, every value in it escaped on the server; null where it holds no player
		const place = function( html ) {
			const parsed = new window.DOMParser().parseFromString( html, 'text/html' );
			if( parsed.querySelector( '.review-player' ) === null )
				return null;
			const nodes = Array.from( parsed.body.childNodes ).map( function( node ) { return dc.importNode( node, true ); } );
			const ids = Array.from( parsed.querySelectorAll( '.review-player, option' ) ).map( function( node ) { return node.getAttribute( 'data-review-file' ) || node.value; } );
			aside.replaceChildren.apply( aside, nodes );
			aside.classList.remove( 'crm-player--empty' );
			if( window.Nino && window.Nino.review && typeof window.Nino.review.init === 'function' )
				window.Nino.review.init( aside );
			watchVersions( aside );
			mark( ids );
			return aside;
		};

		const address = function( file, hash ) {
			const params = cleanQuery( new URLSearchParams( location.search ), true );
			params.delete( 'review-state' );
			params.set( 'review', file );
			params.set( 'tab', 'dateien' );
			replaceAddress( params, hash );
		};

		// A file is opened: the page goes on where the route fails
		const open = function( file, link ) {
			const turn = ++latest;
			ask( source, file ).then( function( html ) {
				if( turn !== latest )
					return;
				if( html === null || place( html ) === null ) {
					location.assign( link.href );
					return;
				}
				address( file, '' );
				if( typeof window.matchMedia === 'function' && window.matchMedia( '(max-width: 1023.98px)' ).matches )
					aside.scrollIntoView( { block: 'start' } );
			} );
		};

		// Another version of the title: the position of the player it replaces goes with it, and it plays on where that played
		const version = function( form, select ) {
			const file = select.value;
			const old = aside.querySelector( 'audio, video' );
			const at = old !== null ? old.currentTime : 0;
			const playing = old !== null && old.paused === false;
			const turn = ++latest;
			ask( source, file ).then( function( html ) {
				if( turn !== latest )
					return;
				if( html === null || place( html ) === null ) {
					form.submit();
					return;
				}
				address( file, '#crm-versions' );
				const media = aside.querySelector( 'audio, video' );
				const next = aside.querySelector( '.crm-versions__select' );
				if( next !== null )
					next.focus( { preventScroll: true } );
				if( media === null )
					return;
				const carry = function() {
					media.currentTime = Number.isFinite( media.duration ) ? Math.min( at, media.duration ) : at;
					if( playing === true ) {
						const promise = media.play();
						if( promise !== undefined && promise !== null && typeof promise.catch === 'function' )
							promise.catch( function() {} );
					}
				};
				if( at > 0 || playing === true ) {
					if( media.readyState >= 1 )
						carry();
					else
						media.addEventListener( 'loadedmetadata', carry, { once: true } );
				}
			} );
		};

		dc.addEventListener( 'click', function( event ) {
			const link = event.target.closest ? event.target.closest( 'a.crm-files__listen, a.crm-files__name' ) : null;
			if( link === null || event.defaultPrevented === true || event.button > 0 || event.metaKey === true || event.ctrlKey === true || event.shiftKey === true || event.altKey === true )
				return;
			const target = new URL( link.href, location.href );
			const file = target.searchParams.get( 'review' );
			if( target.origin !== location.origin || target.pathname !== location.pathname || file === null || /^[a-f0-9]{12}$/.test( file ) === false )
				return;
			event.preventDefault();
			open( file, link );
		} );

		player = { version: version };
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

	// The install the browser offers: kept from the moment it comes, for the button to ask for it. The event may come before the page is ready
	let offered = null;
	let homeCard = null;

	function showInstall() {
		if( offered === null || homeCard === null )
			return;
		const button = homeCard.querySelector( '[data-crm-install]' );
		const android = homeCard.querySelector( '[data-crm-system="android"]' );
		if( button !== null )
			button.hidden = false;
		if( android !== null )
			android.hidden = true;
	}

	if( typeof window !== 'undefined' && typeof window.addEventListener === 'function' ) {
		window.addEventListener( 'beforeinstallprompt', function( event ) {
			// The browser's own offer is stopped only where the card takes it over: on every other page it stays
			if( homeCard === null )
				return;
			event.preventDefault();
			offered = event;
			showInstall();
		} );
	}

	// The device has put the card away: kept in the browser of the device, where the browser lets a page keep anything
	function homePutAway( write ) {
		try {
			if( write === true )
				localStorage.setItem( 'crm-homescreen', '1' );
			return localStorage.getItem( 'crm-homescreen' ) !== null;
		} catch( e ) {
			return false;
		}
	}

	function watchHome() {
		const card = dc.querySelector( '[data-crm-home]' );
		if( card === null )
			return;
		let app = false;
		try { app = window.matchMedia( '(display-mode: standalone)' ).matches === true || window.navigator.standalone === true; } catch( e ) { app = false; }
		if( app === true || window.innerWidth >= 1024 || homePutAway( false ) === true )
			return;
		const away = function() {
			homePutAway( true );
			card.hidden = true;
		};
		const dismiss = card.querySelector( '[data-crm-dismiss]' );
		const install = card.querySelector( '[data-crm-install]' );
		if( dismiss !== null )
			dismiss.addEventListener( 'click', away );
		if( install !== null )
			install.addEventListener( 'click', function() {
				if( offered === null )
					return;
				const asked = offered;
				offered = null;
				// A refused offer is spent: the button goes and the sentence for Android is back
				const refused = function() {
					install.hidden = true;
					const android = card.querySelector( '[data-crm-system="android"]' );
					if( android !== null )
						android.hidden = false;
				};
				try { asked.prompt(); } catch( e ) { refused(); return; }
				if( asked.userChoice !== undefined && typeof asked.userChoice.then === 'function' )
					asked.userChoice.then( function( choice ) {
						if( choice !== undefined && choice.outcome === 'accepted' )
							away();
						else
							refused();
					} ).catch( refused );
				else
					refused();
			} );
		window.addEventListener( 'appinstalled', away );
		homeCard = card;
		card.hidden = false;
		showInstall();
	}

	// The place on the page an address names (#angebot-15) is kept through the sign-in: the form of the login and of the code step post it behind the page they stand on, as the
	// field 'return' has it, and the server leads the sign-in to the page with that place. A page that is no page of the portal - the gate puts the login page in place of one - has
	// the form as well, so this runs before the portal is looked for
	function keepAnchor() {
		if( /^#[A-Za-z0-9_-]{1,64}$/.test( location.hash ) === false )
			return;
		dc.querySelectorAll( 'form.members-login, form.members-code' ).forEach( function( form ) {
			const field = form.querySelector( 'input[name="return"]' );
			if( field !== null && field.value.indexOf( '#' ) === -1 )
				field.value += location.hash;
		} );
	}

	function init() {
		keepAnchor();
		if( dc.querySelector( '.crm-portal' ) === null )
			return;
		watchHome();
		focusRefused();
		followTabs();
		showOpenTab();
		window.addEventListener( 'load', showOpenTab );
		watchDrop();
		watchPlayer();
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
