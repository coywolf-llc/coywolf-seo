/**
 * Coywolf SEO — the SEO panel in the block editor's document sidebar.
 *
 * Replaces the classic meta box (which WordPress 7.0 hides behind a
 * collapsed "Meta Boxes" bar) with a PluginDocumentSettingPanel bound to
 * the `_coywolf_seo` post meta registered in Coywolf_SEO_Metabox.
 *
 * No build step: plain wp.element.createElement against the wp globals.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	// WP 6.6+ exports the panel from wp.editor; 6.0–6.5 only from wp.editPost.
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! registerPlugin || ! PluginDocumentSettingPanel ) {
		return;
	}

	var config = window.coywolf_seo_editor || { pageTypeOptions: [], articleTypeOptions: [], i18n: {} };
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;

	var MOVE_NOTICE_ID = 'coywolf-seo-moved-url';
	var META_KEY = '_coywolf_seo';
	var DEFAULTS = {
		title: '',
		description: '',
		page_type: '',
		article_type: '',
		noindex: false,
		nofollow: false,
		canonical: '',
		primary_category: 0
	};

	function CoywolfSeoPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		}, [] );
		// Live sources for the default Title and Description. They re-render as
		// the author edits the post title or excerpt, so an un-overridden field
		// tracks its source in real time.
		var postTitle = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' );
		}, [] ) || '';
		var postExcerpt = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'excerpt' );
		}, [] ) || '';
		// Post type + the post's assigned categories, resolved to names, for the
		// breadcrumb "Primary category" control (posts with 2+ categories only).
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var catIds = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'categories' ) || [];
		}, [] );
		var catTerms = useSelect( function ( select ) {
			if ( ! catIds || ! catIds.length ) {
				return [];
			}
			return select( 'core' ).getEntityRecords( 'taxonomy', 'category', {
				include: catIds,
				per_page: -1,
				_fields: 'id,name'
			} ) || [];
		}, [ catIds ] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var statusState = useState( config.entityStatus || '' );
		var entityStatus = statusState[ 0 ];
		var setEntityStatus = statusState[ 1 ];
		// While a Title/Description field has focus, show the raw in-progress
		// string instead of the computed override-or-default. Without this,
		// deleting the last character would re-fill the controlled input with
		// the default mid-typing, so clearing-then-retyping would silently
		// store a corrupted override.
		var titleDraft = useState( null );
		var descDraft = useState( null );
		// Last excerpt this panel wrote, so a hand-written excerpt is never
		// overwritten (see mirrorToExcerpt).
		var autoExcerpt = useRef( null );

		function reanalyze() {
			var data = new window.FormData();
			data.append( 'action', 'coywolf_seo_reanalyze' );
			data.append( '_ajax_nonce', config.reanalyzeNonce );
			data.append( 'post_id', config.postId );
			setEntityStatus( config.i18n.analyzing || 'Analyzing…' );
			window
				.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					setEntityStatus( ( json.data && json.data.message ) || '' );
				} )
				.catch( function () {
					setEntityStatus( config.i18n.requestFailed || 'Request failed.' );
				} );
		}

		var raw = meta ? meta[ META_KEY ] : null;
		var seo = Object.assign( {}, DEFAULTS, raw && ! Array.isArray( raw ) ? raw : {} );

		function update( field, value ) {
			// core-data merges meta per key only: always write the full object.
			var next = Object.assign( {}, seo );
			next[ field ] = value;
			var edit = {};
			edit[ META_KEY ] = next;
			editPost( { meta: edit } );
		}

		// A post with no excerpt of its own uses its SEO Description as the
		// excerpt (the front end applies the same fallback). Mirror it into the
		// Excerpt field as it is typed, so the value is real and visible here
		// instead of only appearing once the page is served.
		//
		// Guards, in order of what they protect:
		//  - only runs while the Description is edited, never on load, so
		//    opening a post never marks it dirty on its own;
		//  - only fills an excerpt that is blank, or one this panel wrote
		//    itself (tracked below), so an excerpt you typed is never clobbered;
		//  - keeps syncing while it owns the value, so the excerpt ends up as
		//    the whole description rather than the first keystroke of it.
		function mirrorToExcerpt( value ) {
			var current = postExcerpt || '';
			var ours = '' === current.trim()
				|| ( null !== autoExcerpt.current && current === autoExcerpt.current );
			if ( ! ours ) {
				return;
			}
			autoExcerpt.current = value;
			editPost( { excerpt: value } );
		}

		/* ------------------------------------------------------------------ *
		 * Moved-URL prompt
		 *
		 * Changing a published post's slug leaves its old URL 404ing. The server
		 * records the change and the post editor asks about it, but the block
		 * editor saves over REST and never reloads, so that admin notice cannot
		 * appear at the moment it happens. Watch for a finished (non-autosave)
		 * save and ask right here instead — on the post being edited, which is
		 * the only place the question belongs.
		 * ------------------------------------------------------------------ */
		var isSaving = useSelect( function ( select ) {
			return select( 'core/editor' ).isSavingPost();
		}, [] );
		var isAutosave = useSelect( function ( select ) {
			return select( 'core/editor' ).isAutosavingPost();
		}, [] );
		var wasSaving = useRef( false );

		function moveRequest( op ) {
			var data = new window.FormData();
			data.append( 'action', 'coywolf_seo_move_decision' );
			data.append( '_ajax_nonce', config.moveNonce );
			data.append( 'post_id', config.postId );
			data.append( 'op', op );
			return window
				.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} );
		}

		function decideMove( op ) {
			var notices = wp.data.dispatch( 'core/notices' );
			moveRequest( op )
				.then( function () {
					notices.removeNotice( MOVE_NOTICE_ID );
					if ( 'create' === op ) {
						notices.createNotice(
							'success',
							config.i18n.moveCreated || 'Redirect created.',
							{ type: 'snackbar', isDismissible: true }
						);
					}
				} )
				.catch( function () {} );
		}

		useEffect( function () {
			if ( isSaving ) {
				// Autosaves never change the slug; only track real saves.
				if ( ! isAutosave ) {
					wasSaving.current = true;
				}
				return;
			}
			if ( ! wasSaving.current ) {
				return;
			}
			wasSaving.current = false;
			if ( ! config.moveNonce || ! config.postId ) {
				return;
			}
			moveRequest( 'get' )
				.then( function ( json ) {
					var d = json && json.data;
					if ( ! d || ! d.pending ) {
						return;
					}
					var notices = wp.data.dispatch( 'core/notices' );
					notices.removeNotice( MOVE_NOTICE_ID );
					notices.createNotice(
						'warning',
						( config.i18n.moveAsk || 'The URL changed from %1$s to %2$s. Redirect the old URL to the new one?' )
							.replace( '%1$s', d.oldPath )
							.replace( '%2$s', d.newPath ),
						{
							id: MOVE_NOTICE_ID,
							isDismissible: true,
							actions: [
								{
									label: config.i18n.moveCreate || 'Create 301 redirect',
									onClick: function () {
										decideMove( 'create' );
									}
								},
								{
									label: config.i18n.moveDismiss || 'No thanks',
									onClick: function () {
										decideMove( 'dismiss' );
									}
								}
							]
						}
					);
				} )
				.catch( function () {} );
		}, [ isSaving, isAutosave ] );

		// The default Title is the post's own title; the default Description
		// mirrors the front end — the AI-written summary when one exists, else
		// the manual excerpt. A field only stores a value once it differs from
		// its default (matching how the canonical field treats the permalink).
		var defaultTitle = postTitle;
		var defaultDescription = ( config.aiDescription && '' !== config.aiDescription )
			? config.aiDescription
			: postExcerpt;

		// Breadcrumb "Primary category" options: the post's assigned categories,
		// plus a "first category" default. Labels come from the resolved terms;
		// while they load, the id stands in. Shown only for posts with 2+.
		var nameById = {};
		( catTerms || [] ).forEach( function ( t ) {
			nameById[ t.id ] = t.name;
		} );
		var primaryOptions = [ { label: config.i18n.primaryCategoryFirst || '— First category —', value: '' } ];
		( catIds || [] ).forEach( function ( id ) {
			primaryOptions.push( { label: nameById[ id ] || ( '#' + id ), value: String( id ) } );
		} );
		// Only reflect a stored primary that is still an assigned category;
		// otherwise fall back to "first category" (self-healing display).
		var primaryValue = ( seo.primary_category && catIds.indexOf( seo.primary_category ) !== -1 )
			? String( seo.primary_category )
			: '';
		var showPrimaryCategory = 'post' === postType && catIds && catIds.length >= 2;

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'coywolf-seo-panel',
				title: config.i18n.panelTitle || 'SEO',
				className: 'coywolf-seo-panel'
			},
			// The front/posts pages take their title and description from Site
			// Details, so per-post overrides would be ignored — hide both
			// fields there (config.specialPage).
			config.specialPage
				? null
				: el( TextControl, {
					label: config.i18n.title || 'Title',
					value: null !== titleDraft[ 0 ] ? titleDraft[ 0 ] : ( seo.title || defaultTitle ),
					onFocus: function () {
						titleDraft[ 1 ]( seo.title || defaultTitle );
					},
					onChange: function ( v ) {
						titleDraft[ 1 ]( v );
						update( 'title', v === defaultTitle ? '' : v );
					},
					onBlur: function () {
						titleDraft[ 1 ]( null );
					},
					help: config.i18n.titleHelp || ''
				} ),
			// "Exclude meta description" hides the Description field; a stored
			// override is left untouched (update() always writes the full
			// object, so the hidden value round-trips unchanged).
			( config.specialPage || config.excludeDescription )
				? null
				: el( TextareaControl, {
					label: config.i18n.description || 'Description',
					value: null !== descDraft[ 0 ] ? descDraft[ 0 ] : ( seo.description || defaultDescription ),
					rows: 3,
					onFocus: function () {
						descDraft[ 1 ]( seo.description || defaultDescription );
					},
					onChange: function ( v ) {
						descDraft[ 1 ]( v );
						update( 'description', v === defaultDescription ? '' : v );
						mirrorToExcerpt( v );
					},
					onBlur: function () {
						descDraft[ 1 ]( null );
					},
					help: config.i18n.descriptionHelp || ''
				} ),
			el( SelectControl, {
				label: config.i18n.pageType || 'Schema page type',
				value: seo.page_type,
				options: config.pageTypeOptions,
				onChange: function ( v ) {
					update( 'page_type', v );
				}
			} ),
			el( SelectControl, {
				label: config.i18n.articleType || 'Schema article type',
				value: seo.article_type,
				options: config.articleTypeOptions,
				onChange: function ( v ) {
					update( 'article_type', v );
				}
			} ),
			// Breadcrumb primary category (posts with 2+ categories).
			showPrimaryCategory
				? el( SelectControl, {
					label: config.i18n.primaryCategory || 'Primary category',
					value: primaryValue,
					options: primaryOptions,
					help: config.i18n.primaryCategoryHelp || '',
					onChange: function ( v ) {
						update( 'primary_category', '' === v ? 0 : parseInt( v, 10 ) );
					}
				} )
				: null,
			el( 'p', { className: 'coywolf-seo-robots-label' }, config.i18n.robots || 'Robots' ),
			el( ToggleControl, {
				label: config.i18n.noindex || 'Noindex',
				checked: !! seo.noindex,
				onChange: function ( v ) {
					update( 'noindex', !! v );
				}
			} ),
			el( ToggleControl, {
				label: config.i18n.nofollow || 'Nofollow',
				checked: !! seo.nofollow,
				onChange: function ( v ) {
					update( 'nofollow', !! v );
				}
			} ),
			el( TextControl, {
				label: config.i18n.canonical || 'Canonical link',
				type: 'url',
				// Show the URL actually in use; only a changed value is
				// stored as an override.
				value: seo.canonical || config.permalink || '',
				onChange: function ( v ) {
					update( 'canonical', v === config.permalink ? '' : v );
				}
			} ),
			el(
				'p',
				{
					className: 'coywolf-seo-entity-status',
					role: 'status',
					'aria-live': 'polite',
					style: { color: '#50575e', marginBottom: 0 }
				},
				entityStatus
			),
			config.aiEnabled && config.postId
				? el(
					Button,
					{ variant: 'secondary', isSecondary: true, onClick: reanalyze, style: { marginTop: '8px' } },
					config.i18n.reanalyze || 'Re-analyze entities'
				)
				: null
		);
	}

	registerPlugin( 'coywolf-seo', { render: CoywolfSeoPanel } );
} )( window.wp );
