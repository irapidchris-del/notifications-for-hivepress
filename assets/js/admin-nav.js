/**
 * Notifications for HivePress - settings screen chrome and collapsible groups.
 *
 * Three jobs, all on the Notifications tab only:
 *
 * 1. The shared settings chrome - the quick-links anchor nav, the floating Save control and the
 *    back-to-top button. That block is copied from the reference implementation
 *    (account-menu-enhancer-for-hivepress, assets/js/backend.js) and only the two constants in
 *    CHROME differ. HivePress renders the tab through do_settings_sections(), which emits a bare
 *    <h2> per section with no id and no wrapper (wp-admin/includes/template.php), so there is
 *    nothing to link to server-side; the ids and the nav are added here.
 * 2. The Text section's per-type rows fold into one group per source (Listings, Bookings...).
 *    The server lays the rows down group by group and stamps each input with data-hpnf-group
 *    (alter_settings()), because a table row carries nothing else that says where one group ends.
 * 3. The Types section's long checkbox lists fold behind a "Show options" toggle per group. Those
 *    fields are stamped data-hpnf-collapse server-side.
 *
 * Everything stays in the DOM, merely hidden, so the settings form still posts every value.
 * Without this script the tab renders fully expanded, which is the right fallback.
 *
 * Since 1.5.4 the enqueue only loads this on the Notifications tab, so the hp_notification_ checks
 * are no longer the only thing scoping it. Keep them anyway: they cost one selector, and they are
 * what makes the script safe to load anywhere at all.
 */

/* global hpnfAdminNav */

( function() {
	'use strict';

	/* ======================================================================
	 * SHARED SETTINGS CHROME
	 *
	 * Three pieces of furniture for a long settings tab: the quick-links
	 * anchor nav, a floating Save control and a back-to-top button. Written
	 * to be copied verbatim into the other plugins, so everything below is
	 * self-contained and the only plugin-specific values are the two
	 * constants in CHROME.
	 *
	 * THE HOUSE RULE THIS IMPLEMENTS (resources/hivepress-settings.md, "The
	 * settings anchor nav: one shared marker class", 2026-08-30). Several of
	 * these plugins can decorate one settings screen, so each piece carries
	 * TWO classes: a shared marker that is never styled and exists only so
	 * siblings can find it (`hp-settings-nav`, `hp-settings-save`,
	 * `hp-settings-top`), plus the plugin's own prefixed class carrying all
	 * the CSS. Before rendering a piece, test for its marker with an EXACT
	 * class selector and stand down if a sibling got there first, so the
	 * owner sees one of each however many extensions are active.
	 *
	 * The exact test is the point. The old convention was the substring
	 * `nav[class*="settings-nav"]`, which was blind to three of the plugins
	 * it was meant to see - Account Menu Enhancer's own nav was called
	 * `amehp-section-nav` - and it failed silently.
	 * ================================================================== */

	var CHROME = {
		// This plugin's own class prefix and the field prefix that says the
		// rendered tab belongs to it. The only two lines to change on a copy.
		prefix: 'hpnf',
		fieldPrefix: 'hp_notification_',
	};

	/*
	 * The only thing in this block that reaches outside itself, and it is
	 * a read of one localised object with a fallback for every string. That
	 * is deliberate: the block is copied verbatim across the extension
	 * family, so anything it depended on would have to be copied with it,
	 * and a copy that landed without its dependency would break nothing
	 * until somebody opened that plugin's settings screen.
	 */
	function chromeLabels() {
		return ( window.hpnfAdminNav && window.hpnfAdminNav.labels ) || {};
	}

	/**
	 * The settings form, but only when this plugin's tab is the one rendered.
	 *
	 * Gating on our own fields rather than on heading count, because a count
	 * is true of every HivePress tab: Geolocation Plus 1.1.0 gated that way
	 * and decorated other plugins' tabs until 1.1.1.
	 *
	 * @return {Element|null}
	 */
	function chromeForm() {
		var form = document.querySelector( '.hp-page form.hp-form--table' );

		if ( ! form || ! form.querySelector( '[name^="' + CHROME.fieldPrefix + '"]' ) ) {
			return null;
		}

		return form;
	}

	/**
	 * The quick-links anchor nav.
	 *
	 * WordPress renders settings sections as bare <h2>s through
	 * do_settings_sections(), with no hook to add anchors, so the ids and the
	 * nav have to be added here.
	 *
	 * @param {Element} form Settings form.
	 */
	function addSectionNav( form ) {
		if ( document.querySelector( 'nav.hp-settings-nav' ) ) {
			return;
		}

		// Direct children only. A settings section is a direct child of the
		// form; an h2 nested inside a panel or a card is not a section and
		// must not become a quick link.
		var headings = form.querySelectorAll( ':scope > h2' );

		if ( headings.length < 2 ) {
			return;
		}

		var nav = document.createElement( 'nav' ),
			navLabel = chromeLabels().jumpTo || 'Jump to a section:';

		nav.className = 'hp-settings-nav ' + CHROME.prefix + '-settings-nav';

		/*
		 * The bar opens with its own wording, not just an aria-label.
		 *
		 * A row of pills with nothing in front of it reads as decoration, and
		 * the one audience that was told what it is - a screen reader, through
		 * the aria-label - is the one audience that could not see the pills
		 * anyway. The visible text is part of the house chrome spec
		 * (resources/hivepress-settings.md, "The settings anchor nav"), so it
		 * carries its own class for the sibling plugins to copy, and the
		 * aria-label is dropped: the text now names the nav for everybody, and
		 * leaving both would have a screen reader announce the name twice.
		 */
		var label = document.createElement( 'span' );

		label.className = CHROME.prefix + '-settings-nav__label';
		label.textContent = navLabel;

		nav.appendChild( label );

		headings.forEach( function ( heading, index ) {

			/*
			 * Reuse the id WordPress already put on the heading and mint one
			 * only where there is none. Overwriting it breaks every link,
			 * bookmark and sibling script pointing at the real
			 * `wp-settings-section-{name}` id, which is what the first
			 * version of this nav did.
			 */
			if ( ! heading.id ) {
				heading.id = CHROME.prefix + '-section-' + index;
			}

			heading.classList.add( CHROME.prefix + '-section-heading' );

			if ( 0 === index ) {
				heading.classList.add( CHROME.prefix + '-section-heading--first' );
			}

			var link = document.createElement( 'a' );

			link.href = '#' + heading.id;

			// textContent on both ends, so heading markup can never become
			// link markup.
			link.textContent = heading.textContent;

			nav.appendChild( link );
		} );

		form.insertBefore( nav, headings[ 0 ] );
	}

	/**
	 * The floating Save control.
	 *
	 * It submits the real form rather than carrying any save logic of its
	 * own: requestSubmit() runs the same validation and the same submit
	 * handlers as pressing the button at the bottom of the page, so there is
	 * only ever one way to save. The real button stays exactly where it was.
	 *
	 * @param {Element} form Settings form.
	 */
	function addFloatingSave( form ) {
		if ( document.querySelector( '.hp-settings-save' ) ) {
			return;
		}

		var submit = form.querySelector( 'input[type="submit"], button[type="submit"]' );

		if ( ! submit ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			text = document.createElement( 'span' ),
			label = chromeLabels().save || 'Save Changes';

		button.type = 'button';

		/*
		 * Core's own button classes, so WordPress paints it.
		 *
		 * This control IS the form's Save button, moved somewhere reachable,
		 * so it has to look like it - and "looks like it" is not one colour.
		 * Every user can pick an Admin Colour Scheme under Users > Profile,
		 * and each scheme repaints .wp-core-ui .button-primary. Painting our
		 * own #2271b1 matched the default scheme and nothing else: measured on
		 * 2026-08-30 under Modern, the real button was rgb(56,88,233) and this
		 * tab rgb(34,113,177), side by side on the same screen. The prefixed
		 * class is kept for layout only.
		 */
		button.className = 'hp-settings-save ' + CHROME.prefix + '-settings-save button button-primary';
		button.setAttribute( 'aria-label', label );

		icon.className = 'dashicons dashicons-saved';
		icon.setAttribute( 'aria-hidden', 'true' );

		text.className = CHROME.prefix + '-settings-save__text';
		text.textContent = label;

		button.appendChild( icon );
		button.appendChild( text );

		button.addEventListener( 'click', function () {

			// requestSubmit() fires the submit event and the browser's own
			// validation; form.submit() would skip both. Older browsers
			// without it get the real button pressed instead, which is the
			// same thing by a longer route.
			if ( form.requestSubmit ) {
				form.requestSubmit( submit );
			} else {
				submit.click();
			}
		} );

		document.body.appendChild( button );
	}

	/**
	 * The back-to-top button.
	 *
	 * Hidden until the page has actually scrolled, so it never covers
	 * anything on a tab short enough not to need it.
	 */
	function addBackToTop() {
		if ( document.querySelector( '.hp-settings-top' ) ) {
			return;
		}

		var button = document.createElement( 'button' ),
			icon = document.createElement( 'span' ),
			label = chromeLabels().backToTop || 'Back to top';

		button.type = 'button';

		// Core's secondary button, for the same reason as the Save tab above:
		// its blue is the scheme's blue, not a hex of ours.
		button.className = 'hp-settings-top ' + CHROME.prefix + '-settings-top button';
		button.setAttribute( 'aria-label', label );
		button.title = label;
		button.hidden = true;

		icon.className = 'dashicons dashicons-arrow-up-alt2';
		icon.setAttribute( 'aria-hidden', 'true' );

		button.appendChild( icon );

		button.addEventListener( 'click', function () {

			// A reader who has asked for reduced motion is asking not to be
			// moved through a long page; "auto" jumps instead of animating.
			var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			window.scrollTo( {
				top: 0,
				behavior: reduced ? 'auto' : 'smooth',
			} );

			// Focus follows the scroll, so a keyboard user carries on from the
			// top of the page rather than from a button that is now off screen.
			var heading = document.querySelector( '.hp-page__title' );

			if ( heading ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus( { preventScroll: true } );
			}
		} );

		document.body.appendChild( button );

		/*
		 * The show/hide runs straight off the scroll event.
		 *
		 * It used to be deferred into requestAnimationFrame, which is the
		 * usual advice for scroll handlers - and it meant the button never
		 * appeared at all whenever the page was not being painted, because a
		 * browser pauses rAF on a hidden page and the callback simply never
		 * ran. Caught by measurement on 2026-08-30: document.hidden was true,
		 * the page was scrolled to 1500px, and the button stayed hidden.
		 * Nobody is looking at a page in that state, so the symptom was
		 * invisible rather than harmless - it would equally have hidden a
		 * genuine failure. The work here is two property reads and a boolean
		 * write, which is cheap enough to do on the event itself, so the
		 * optimisation bought nothing and cost correctness.
		 */
		function update() {
			button.hidden = ( window.pageYOffset || document.documentElement.scrollTop ) < 300;
		}

		window.addEventListener( 'scroll', update, { passive: true } );

		update();
	}

	/**
	 * Adds every piece of chrome, one tick after ready.
	 *
	 * The delay is deliberate: load order between plugins is not something
	 * any of them controls, so a sibling whose hook registered first may
	 * still be placing its own nav when this runs. One tick lets it finish,
	 * and the stand-down guards then see it.
	 */
	function addSettingsChrome() {
		window.setTimeout( function () {
			var form = chromeForm();

			if ( ! form ) {
				return;
			}

			addSectionNav( form );
			addFloatingSave( form );
			addBackToTop();
		}, 0 );
	}

	/**
	 * Folds the Text section's rows into one collapsible group per source.
	 *
	 * @param {Element} form Settings form.
	 */
	function initTextGroups( form ) {
		var rows = form.querySelectorAll( 'tr' );
		var groups = [];
		var current = null;

		Array.prototype.forEach.call( rows, function( row ) {
			var input = row.querySelector( 'input[data-hpnf-group]' );

			if ( ! input ) {
				current = null;

				return;
			}

			var name = input.getAttribute( 'data-hpnf-group' );

			if ( ! current || current.name !== name ) {
				current = {
					name: name,
					rows: [],
				};

				groups.push( current );
			}

			current.rows.push( row );
		} );

		// One group is not worth folding; the fold would only add a click.
		if ( groups.length < 2 ) {
			return;
		}

		groups.forEach( function( group ) {
			var header = document.createElement( 'tr' );
			header.className = 'hpnf-group-row';

			var cell = document.createElement( 'td' );
			cell.colSpan = 2;

			var toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'hpnf-group-toggle';

			var title = document.createElement( 'span' );
			title.className = 'hpnf-group-toggle__name';
			title.textContent = group.name;

			// Row pairs per type, so the count reads as "how many notifications", not "how many
			// boxes". Digits only, so nothing here needs translating.
			var count = document.createElement( 'span' );
			count.className = 'hpnf-group-toggle__count';
			count.textContent = String( group.rows.length );

			var chevron = document.createElement( 'span' );
			chevron.className = 'dashicons dashicons-arrow-down-alt2 hpnf-group-toggle__chevron';
			chevron.setAttribute( 'aria-hidden', 'true' );

			toggle.appendChild( title );
			toggle.appendChild( count );
			toggle.appendChild( chevron );
			cell.appendChild( toggle );
			header.appendChild( cell );

			group.rows[0].parentNode.insertBefore( header, group.rows[0] );

			function setOpen( open ) {
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				header.classList.toggle( 'hpnf-group-row--open', open );

				group.rows.forEach( function( row ) {
					row.hidden = ! open;
				} );
			}

			setOpen( false );

			toggle.addEventListener( 'click', function() {
				setOpen( 'false' === toggle.getAttribute( 'aria-expanded' ) );
			} );
		} );
	}

	/**
	 * Folds each marked checkbox list behind a Show options toggle.
	 *
	 * @param {Element} form Settings form.
	 */
	function initCheckboxFolds( form ) {
		var strings = window.hpnfAdminNav || {};

		Array.prototype.forEach.call( form.querySelectorAll( '.hp-field[data-hpnf-collapse]' ), function( field ) {
			var list = field.querySelector( 'ul' );

			if ( ! list ) {
				return;
			}

			var boxes = field.querySelectorAll( 'input[type="checkbox"]' );

			var toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'button-link hpnf-fold-toggle';

			var text = document.createElement( 'span' );

			// "3/12" alongside the action, so a closed list still says how much of it is on.
			var count = document.createElement( 'span' );
			count.className = 'hpnf-fold-toggle__count';

			var chevron = document.createElement( 'span' );
			chevron.className = 'dashicons dashicons-arrow-down-alt2 hpnf-fold-toggle__chevron';
			chevron.setAttribute( 'aria-hidden', 'true' );

			toggle.appendChild( text );
			toggle.appendChild( count );
			toggle.appendChild( chevron );

			field.insertBefore( toggle, list );

			function recount() {
				count.textContent = field.querySelectorAll( 'input[type="checkbox"]:checked' ).length + '/' + boxes.length;
			}

			function setOpen( open ) {
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				toggle.classList.toggle( 'hpnf-fold-toggle--open', open );
				text.textContent = open ? ( strings.hide || 'Hide options' ) : ( strings.show || 'Show options' );
				list.hidden = ! open;
			}

			recount();
			setOpen( false );

			toggle.addEventListener( 'click', function() {
				setOpen( 'false' === toggle.getAttribute( 'aria-expanded' ) );
			} );

			field.addEventListener( 'change', recount );
		} );
	}

	function init() {
		var form = document.querySelector( 'form.hp-form--table' );

		if ( ! form || ! form.querySelector( '[name^="hp_notification_"]' ) ) {
			return;
		}

		// The chrome runs one tick later and re-tests the page itself, so it is safe to ask for it
		// here whether or not the folds below find anything to do.
		addSettingsChrome();

		initTextGroups( form );
		initCheckboxFolds( form );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
