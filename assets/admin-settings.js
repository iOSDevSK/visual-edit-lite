/**
 * Behaviour for the two settings screens that have any: Form Settings and
 * SEO & Sharing. Loaded only on those screens (see the enqueue() method of
 * Clara_VE_Form_Settings and Clara_VE_SEO_Settings).
 *
 * Every block looks for its own elements first and does nothing without them,
 * so one file serves both screens and neither has to know about the other.
 */
( function () {
	'use strict';

	var strings = window.claraVeSettings || {};

	// Form Settings: the opt-in rows that belong to the mode not chosen are
	// noise — three email fields for a flow the site is not running.
	var mode = document.getElementById( 'clara_ve_optin_mode' );
	function syncOptin() {
		var rows = document.querySelectorAll( '.cve-optin-row' );
		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].style.display = ( rows[ i ].getAttribute( 'data-optin' ) === mode.value ) ? '' : 'none';
		}
	}
	if ( mode ) {
		mode.addEventListener( 'change', syncOptin );
		syncOptin();
	}

	// Form Settings: the file a confirmed subscriber is sent.
	var pick = document.getElementById( 'cve-pick-file' );
	if ( pick ) {
		pick.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			var frame = window.wp.media( { title: strings.chooseFile || '', multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				document.getElementById( 'clara_ve_optin_deliver_file' ).value = att.url;
			} );
			frame.open();
		} );
	}

	// Form Settings: only the chosen mailer's fields.
	var mailer = document.getElementById( 'clara_ve_mailer' );
	function syncMailer() {
		var boxes = document.querySelectorAll( '.cve-mailer-section' );
		for ( var i = 0; i < boxes.length; i++ ) {
			boxes[ i ].style.display = ( boxes[ i ].getAttribute( 'data-mailer' ) === mailer.value ) ? '' : 'none';
		}
	}
	if ( mailer ) {
		mailer.addEventListener( 'change', syncMailer );
		syncMailer();
	}

	// SEO & Sharing: the logo and the default share image. Delegated, because
	// the screen has more than one picker and they all behave the same.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.cve-seo-pick' ) : null;
		if ( ! btn || ! window.wp || ! window.wp.media ) {
			return;
		}
		e.preventDefault();
		var input = document.getElementById( btn.dataset.target );
		var thumb = document.querySelector( '.cve-seo-thumb[data-for="' + btn.dataset.target + '"]' );
		var frame = window.wp.media( { library: { type: 'image' }, multiple: false, button: { text: strings.useImage || '' } } );
		frame.on( 'select', function () {
			var picked = frame.state().get( 'selection' ).first();
			if ( ! picked ) {
				return;
			}
			input.value = picked.get( 'url' );
			if ( thumb ) {
				thumb.src = input.value;
				thumb.style.display = '';
			}
		} );
		frame.open();
	} );
} )();
