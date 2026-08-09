/**
 * Front-end behavior for videos marked "play automatically on scroll" in the
 * Visual Editor (they carry the `scroll-video` class). Mirrors the original
 * hand-authored site's own scroll-video script exactly — a video plays ONCE
 * when it scrolls into view, then rests on its final frame (no loop) — but
 * provides it generically for every page/site the plugin runs on, not just a
 * page that happened to ship the inline script.
 *
 * Deliberately NOT active inside the edit preview iframe (clara_edit=1): there
 * the editor keeps videos static and selectable, and the panel checkbox is the
 * affordance. Idempotent with any theme copy of this behavior — a second
 * play() call on an already-playing video is a no-op.
 */
( function () {
	if ( window.location.search.indexOf( 'clara_edit=1' ) !== -1 ) {
		return; // editing — leave videos alone.
	}

	function init() {
		var videos = document.querySelectorAll( 'video.scroll-video' );
		if ( ! videos.length ) {
			return;
		}

		// Respect reduced-motion: don't animate, just show the final frame.
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			Array.prototype.forEach.call( videos, function ( video ) {
				var showLastFrame = function () {
					if ( video.duration && isFinite( video.duration ) ) {
						video.currentTime = Math.max( 0, video.duration - 0.05 );
					}
				};
				if ( video.readyState >= 1 ) {
					showLastFrame();
				} else {
					video.addEventListener( 'loadedmetadata', showLastFrame, { once: true } );
				}
			} );
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			// No observer — play immediately as a graceful fallback.
			Array.prototype.forEach.call( videos, function ( video ) {
				var p = video.play();
				if ( p && p.catch ) {
					p.catch( function () {} );
				}
			} );
			return;
		}

		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					var p = entry.target.play();
					if ( p && p.catch ) {
						p.catch( function () {} ); // autoplay policy can reject — muted videos are allowed, but be safe.
					}
					io.unobserve( entry.target ); // play once.
				}
			} );
		}, { threshold: 0.4 } );

		Array.prototype.forEach.call( videos, function ( video ) {
			// A muted, inline video is required for programmatic autoplay under
			// browser policies — the editor sets both when the option is turned
			// on, but enforce here too in case older markup is missing them.
			video.muted = true;
			video.setAttribute( 'muted', '' );
			video.setAttribute( 'playsinline', '' );
			io.observe( video );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
