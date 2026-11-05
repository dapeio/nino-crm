/**
 *	Crm - Portal
 *	portal.js		The one script of the customer portal, bundled into the project's own /.cache/script.js by \Crm\Crm::init() (the module adds it
 *							with \Nino\Html::addAsset(), as it adds portal.css). It wires what the pages cannot say in markup alone, and every page works without
 *							it: the tab of a project page is written into the address when it is switched (?tab=angebot, dateien, belege), so that a reload and
 *							the back button keep it (the tabs are links to the sections, and once the kernel's script has made them tabs a click on one does not
 *							leave the page); a message the address carries (?state=..., ?sign-state=...) is taken out of the address once the page has shown it,
 *							so that a reload does not say it again; the card or the row an anchor names (#angebot-<id>, #beleg-<id>), or the row of the document
 *							Sign has just brought back (data-sign-state), is unfolded and brought into view, also when it stands in a tab the kernel's script has
 *							hidden; and the drop zone of the upload says that a file hangs over it. No markup is made here, nothing is sent and nothing is stored.
 */
( function() {

	'use strict';

	const dc = document;

	// The query without the parts that say nothing twice: the message of a form and the document it was about
	function cleanQuery( params, dropTab ) {
		params.delete( 'state' );
		params.delete( 'field' );
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
		if( target === null )
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
			target.focus( { preventScroll: true } );
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

	function init() {
		if( dc.querySelector( '.crm-portal' ) === null )
			return;
		followTabs();
		watchDrop();
		forgetState();
		revealTarget();
		window.addEventListener( 'hashchange', revealTarget );
	}

	if( dc.readyState === 'loading' )
		dc.addEventListener( 'DOMContentLoaded', init );
	else
		init();

} )();
