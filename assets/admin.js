( function () {
	const { __, _n, sprintf } = wp.i18n;
	const { applyFilters, doAction } = wp.hooks;
	const data = window.importrightAdmin || {};
	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );

	const ACTIVE = [ 'pending', 'running' ];

	function el( tag, attrs = {}, children = [] ) {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ key, value ] ) => {
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'className' === key ) {
				node.className = value;
			} else if ( true === value ) {
				node.setAttribute( key, '' );
			} else {
				node.setAttribute( key, value );
			}
		} );
		children.forEach( ( child ) => child && node.append( child ) );
		return node;
	}

	function number( value ) {
		return Number( value || 0 ).toLocaleString();
	}

	function bytes( value ) {
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let size = Number( value || 0 );
		let unit = 0;
		while ( size >= 1024 && unit < units.length - 1 ) {
			size /= 1024;
			unit++;
		}
		return ( unit ? size.toFixed( 1 ) : size ) + ' ' + units[ unit ];
	}

	function clip( text, max = 200 ) {
		text = String( text );
		return text.length > max ? text.slice( 0, max ) + '…' : text;
	}

	const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	async function call( action, fields = {} ) {
		const body = new FormData();
		body.append( 'action', 'importright_' + action );
		body.append( 'nonce', data.nonce );
		Object.entries( fields ).forEach( ( [ key, value ] ) => {
			if ( value instanceof Blob ) {
				body.append( key, value, value.name );
			} else {
				body.append( key, 'object' === typeof value ? JSON.stringify( value ) : String( value ) );
			}
		} );

		let json;
		let response;
		try {
			response = await fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
			json = await response.json();
		} catch ( e ) {
			const error = new Error( __( 'The server returned an unexpected response. Check the PHP error log, or lower the records per batch.', 'total-import' ) );
			error.code = 'importright_response';
			throw error;
		}
		if ( ! json || ! json.success ) {
			const error = new Error( ( json && json.data && json.data.message ) || __( 'Something went wrong.', 'total-import' ) );
			error.code = ( json && json.data && json.data.code ) || '';
			throw error;
		}
		return json.data;
	}

	function notice( type, message ) {
		const box = $( '#importright-notice' );
		if ( ! box ) {
			return;
		}
		box.className = 'notice inline notice-' + type + ( message ? '' : ' hidden' );
		$( 'p', box ).textContent = message || '';
	}

	/* Delete confirmations on the imports list. */
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( '[data-importright-confirm]' );
		if ( link && ! window.confirm( link.dataset.importrightConfirm ) ) {
			event.preventDefault();
		}
	} );

	/* Extra count tiles from run.extra_counts, a list of { key, label, value }. */

	function extraCounts( run ) {
		return ( Array.isArray( run.extra_counts ) ? run.extra_counts : [] ).filter( ( extra ) => extra && extra.label && ! isNaN( Number( extra.value ) ) );
	}

	/* Run panel: dry run and live run through the AJAX driver. */

	function runPanel( root, options ) {
		const progress = $( '.importright-progress', root );
		const bar = $( 'progress', progress );
		const status = $( '.importright-progress-status', progress );
		const result = $( '.importright-result', root );
		const startButtons = $$( '[data-run]', root );
		let current = null;
		let busy = false;
		let background = false;

		function describe( run ) {
			const counts = run.counts;
			const done = run.total
				? sprintf(
						/* translators: 1: records read, 2: total records */
						__( '%1$s of %2$s records read.', 'total-import' ),
						number( counts.processed ),
						number( run.total )
				  )
				: sprintf(
						/* translators: %s: records read */
						__( '%s records read.', 'total-import' ),
						number( counts.processed )
				  );
			return (
				done +
				' ' +
				sprintf(
					/* translators: 1: created, 2: updated, 3: unchanged, 4: failed */
					__( '%1$s created, %2$s updated, %3$s unchanged, %4$s failed.', 'total-import' ),
					number( counts.created ),
					number( counts.updated ),
					number( counts.unchanged ),
					number( counts.failed )
				) +
				extraCounts( run )
					.map( ( extra ) => ' ' + extra.label + ': ' + number( extra.value ) + '.' )
					.join( '' )
			);
		}

		function show( run ) {
			current = run;
			if ( null === run.percent || undefined === run.percent ) {
				bar.removeAttribute( 'value' );
			} else {
				bar.value = run.percent;
			}
			const prefix = background ? __( 'Running in the background.', 'total-import' ) + ' ' : '';
			status.textContent = prefix + describe( run );
		}

		function setBusy( value ) {
			busy = value;
			startButtons.forEach( ( button ) => ( button.disabled = value ) );
			progress.hidden = ! value;
			$$( '[data-run-control]', progress ).forEach( ( button ) => ( button.disabled = false ) );
		}

		async function start( mode ) {
			if ( busy ) {
				return;
			}
			if ( options.beforeStart && ! ( await options.beforeStart() ) ) {
				return;
			}
			const importId = options.importId();
			if ( ! importId ) {
				return;
			}
			result.hidden = true;
			result.replaceChildren();
			background = false;
			setBusy( true );
			bar.removeAttribute( 'value' );
			status.textContent = 'dry' === mode ? __( 'Starting the dry run…', 'total-import' ) : __( 'Starting the import…', 'total-import' );

			let run;
			try {
				run = ( await call( 'run_start', { import_id: importId, dry_run: 'dry' === mode ? 1 : 0 } ) ).run;
			} catch ( error ) {
				setBusy( false );
				finishWithError( error.message );
				return;
			}
			show( run );
			await drive( run );
		}

		async function drive( run ) {
			while ( ACTIVE.includes( run.status ) ) {
				try {
					run = ( await call( background ? 'run_status' : 'run_step', { run_id: run.id } ) ).run;
				} catch ( error ) {
					if ( 'importright_locked' === error.code ) {
						await sleep( 2000 );
						continue;
					}
					setBusy( false );
					finishWithError( error.message );
					return;
				}
				show( run );
				if ( background && ACTIVE.includes( run.status ) ) {
					await sleep( 3000 );
				}
			}
			setBusy( false );
			await finish( run );
		}

		function finishWithError( message ) {
			result.hidden = false;
			result.replaceChildren( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: message } ) ] ) );
			result.focus();
		}

		async function finish( run ) {
			const dry = 'dry_run' === run.mode;
			const titles = {
				completed: dry ? __( 'Dry run finished', 'total-import' ) : __( 'Import finished', 'total-import' ),
				failed: dry ? __( 'Dry run failed', 'total-import' ) : __( 'Import failed', 'total-import' ),
				cancelled: dry ? __( 'Dry run cancelled', 'total-import' ) : __( 'Import cancelled', 'total-import' ),
			};
			const counts = run.counts;
			const nodes = [ el( 'h3', { text: titles[ run.status ] || run.status } ) ];

			if ( dry && 'completed' === run.status ) {
				nodes.push( el( 'p', { text: __( 'Nothing was written. These are the numbers a real run would give now.', 'total-import' ) } ) );
			}
			if ( run.message && 'completed' !== run.status ) {
				nodes.push(
					el( 'div', { className: 'notice inline ' + ( 'failed' === run.status ? 'notice-error' : 'notice-warning' ) }, [ el( 'p', { text: run.message } ) ] )
				);
			}

			const labels = dry
				? {
						created: __( 'Would be created', 'total-import' ),
						updated: __( 'Would be updated', 'total-import' ),
						unchanged: __( 'Unchanged', 'total-import' ),
						skipped: __( 'Skipped', 'total-import' ),
						failed: __( 'Would fail', 'total-import' ),
						processed: __( 'Records read', 'total-import' ),
				  }
				: {
						created: __( 'Created', 'total-import' ),
						updated: __( 'Updated', 'total-import' ),
						unchanged: __( 'Unchanged', 'total-import' ),
						skipped: __( 'Skipped', 'total-import' ),
						failed: __( 'Failed', 'total-import' ),
						processed: __( 'Records read', 'total-import' ),
				  };
			nodes.push(
				el(
					'dl',
					{ className: 'importright-counts-list' },
					Object.entries( labels )
						.flatMap( ( [ key, label ] ) => [
							el( 'div', { className: 'importright-count importright-count-' + key }, [ el( 'dt', { text: label } ), el( 'dd', { text: number( counts[ key ] ) } ) ] ),
						] )
						.concat(
							extraCounts( run ).map( ( extra ) =>
								el( 'div', { className: 'importright-count importright-count-' + String( extra.key || '' ).replace( /[^\w-]/g, '' ) }, [ el( 'dt', { text: extra.label } ), el( 'dd', { text: number( extra.value ) } ) ] )
							)
						)
				)
			);

			const facts = [];
			if ( null !== run.duration ) {
				/* translators: %s: number of seconds */
				facts.push( sprintf( __( 'Took %s seconds.', 'total-import' ), number( run.duration ) ) );
			}
			if ( run.log.errors || run.log.notices ) {
				facts.push(
					sprintf(
						/* translators: 1: errors, 2: notices */
						__( '%1$s errors and %2$s notices were logged.', 'total-import' ),
						number( run.log.errors ),
						number( run.log.notices )
					)
				);
			}
			if ( facts.length ) {
				nodes.push( el( 'p', { text: facts.join( ' ' ) } ) );
			}

			const links = [];
			if ( run.log.errors || run.log.notices ) {
				// A POST form keeps the nonce out of the URL.
				links.push(
					el( 'form', { method: 'post', action: data.postUrl, className: 'importright-inline-form' }, [
						el( 'input', { type: 'hidden', name: 'action', value: data.logCsv.action } ),
						el( 'input', { type: 'hidden', name: 'run_id', value: String( run.id ) } ),
						el( 'input', { type: 'hidden', name: '_wpnonce', value: data.logCsv.nonce } ),
						el( 'button', { type: 'submit', className: 'button', text: __( 'Download the error log (CSV)', 'total-import' ) } ),
					] )
				);
			}
			links.push( el( 'a', { href: data.urls.history + '&run=' + run.id, className: 'button', text: __( 'Run history', 'total-import' ) } ) );
			if ( dry && 'completed' === run.status ) {
				const again = el( 'button', { type: 'button', className: 'button button-primary', text: __( 'Run import now', 'total-import' ) } );
				again.addEventListener( 'click', () => start( 'live' ) );
				links.unshift( again );
			}
			nodes.push( el( 'div', { className: 'importright-run-links' }, links ) );

			result.replaceChildren( ...nodes );
			result.hidden = false;

			if ( run.log.errors ) {
				await appendLog( run );
			}
			if ( dry && run.log.previews ) {
				await appendPreview( run );
			}
			result.focus();
		}

		async function appendLog( run ) {
			let found;
			try {
				found = await call( 'run_log', { run_id: run.id, level: 'error', per_page: 10 } );
			} catch ( error ) {
				return;
			}
			if ( ! found.entries.length ) {
				return;
			}
			const rows = found.entries.map( ( entry ) =>
				el( 'tr', {}, [ el( 'td', { className: 'num', text: number( entry.row ) } ), el( 'td', { text: entry.key } ), el( 'td', { text: entry.message } ) ] )
			);
			result.append(
				el( 'h4', {
					text: sprintf(
						/* translators: 1: errors shown, 2: total errors */
						__( 'First %1$s of %2$s errors', 'total-import' ),
						number( found.entries.length ),
						number( found.total )
					),
				} ),
				el( 'div', { className: 'importright-scroll', tabindex: '0', role: 'region', 'aria-label': __( 'Errors', 'total-import' ) }, [
					el( 'table', { className: 'widefat striped importright-log' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', { scope: 'col', className: 'num', text: __( 'Row', 'total-import' ) } ),
								el( 'th', { scope: 'col', text: __( 'Key', 'total-import' ) } ),
								el( 'th', { scope: 'col', text: __( 'Message', 'total-import' ) } ),
							] ),
						] ),
						el( 'tbody', {}, rows ),
					] ),
				] )
			);
		}

		async function appendPreview( run ) {
			let found;
			try {
				found = await call( 'run_preview', { run_id: run.id, per_page: 25 } );
			} catch ( error ) {
				return;
			}
			const actions = {
				create: __( 'Create', 'total-import' ),
				update: __( 'Update', 'total-import' ),
				unchanged: __( 'Unchanged', 'total-import' ),
				skip: __( 'Skip', 'total-import' ),
				fail: __( 'Fail', 'total-import' ),
			};
			const rows = found.rows.map( ( row ) => {
				const changes = el(
					'ul',
					{ className: 'importright-changes' },
					row.changes.map( ( change ) => {
						const parts = [ el( 'span', { className: 'importright-field-name', text: change.field } ), document.createTextNode( ' ' ) ];
						if ( null !== change.old && 'create' !== row.action ) {
							parts.push( el( 'del', { text: clip( change.old ) } ), document.createTextNode( ' → ' ) );
						}
						parts.push( el( 'ins', { text: null === change.new ? __( '(cleared)', 'total-import' ) : clip( change.new ) } ) );
						return el( 'li', {}, parts );
					} )
				);
				const extra = [ ...row.downloads.map( ( url ) => sprintf( /* translators: %s: image URL */ __( 'Downloads %s', 'total-import' ), url ) ), ...row.messages ];
				return el( 'tr', {}, [
					el( 'td', { className: 'num', text: number( row.row ) } ),
					el( 'td', { className: 'importright-key', text: row.key } ),
					el( 'td', {}, [
						el( 'span', { className: 'importright-status importright-action-' + row.action, text: actions[ row.action ] || row.action } ),
						row.object_id ? el( 'span', { className: 'importright-muted', text: ' #' + row.object_id } ) : null,
					] ),
					el( 'td', {}, [ row.changes.length ? changes : el( 'span', { className: 'importright-muted', text: '—' } ), extra.length ? el( 'ul', { className: 'importright-messages' }, extra.map( ( text ) => el( 'li', { text } ) ) ) : null ] ),
				] );
			} );
			result.append(
				el( 'h4', {
					text: sprintf(
						/* translators: 1: rows shown, 2: rows stored */
						__( 'Preview of the first %1$s records (of %2$s with details)', 'total-import' ),
						number( found.rows.length ),
						number( found.total )
					),
				} ),
				el( 'div', { className: 'importright-scroll', tabindex: '0', role: 'region', 'aria-label': __( 'Dry run preview', 'total-import' ) }, [
					el( 'table', { className: 'widefat striped importright-preview-rows' }, [
						el( 'thead', {}, [
							el( 'tr', {}, [
								el( 'th', { scope: 'col', className: 'num', text: __( 'Row', 'total-import' ) } ),
								el( 'th', { scope: 'col', text: __( 'Key', 'total-import' ) } ),
								el( 'th', { scope: 'col', text: __( 'Action', 'total-import' ) } ),
								el( 'th', { scope: 'col', text: __( 'Changes (old → new)', 'total-import' ) } ),
							] ),
						] ),
						el( 'tbody', {}, rows ),
					] ),
				] )
			);
		}

		startButtons.forEach( ( button ) => button.addEventListener( 'click', () => start( button.dataset.run ) ) );

		$( '[data-run-control="cancel"]', progress ).addEventListener( 'click', async ( event ) => {
			if ( ! current ) {
				return;
			}
			event.currentTarget.disabled = true;
			try {
				show( ( await call( 'run_cancel', { run_id: current.id } ) ).run );
			} catch ( error ) {
				status.textContent = error.message;
			}
		} );

		$( '[data-run-control="background"]', progress ).addEventListener( 'click', async ( event ) => {
			if ( ! current ) {
				return;
			}
			event.currentTarget.disabled = true;
			try {
				await call( 'run_background', { run_id: current.id } );
				background = true;
				status.textContent = __( 'Handed over to the background queue. You can leave this page; the run continues.', 'total-import' );
			} catch ( error ) {
				status.textContent = error.message;
			}
		} );

		return { start };
	}

	/* Run screen. */

	if ( 'importright-run' === data.screen ) {
		const panel = runPanel( $( '#importright-run' ), { importId: () => data.importId } );
		if ( data.autostart ) {
			panel.start( data.autostart );
		}
	}

	if ( 'importright-import' === data.screen ) {
		wizard();
	}

	if ( 'importright-history' === data.screen && $( '#importright-run-detail' ) ) {
		runDetail( $( '#importright-run-detail' ) );
	}

	if ( 'importright-migrate' === data.screen ) {
		migrate();
	}

	/* Import wizard. */

	function wizard() {
		const config = data.wizard;
		const form = $( '#importright-wizard' );
		const steps = $$( '.importright-step', form ).map( ( section ) => section.dataset.step );
		const state = {
			importId: data.importId || 0,
			source: { type: 'upload', file: '', original_name: '' },
			inspected: null,
			inspectedKey: '',
			records: [],
			columns: [],
			step: steps[ 0 ],
			reached: data.definition ? steps.length - 1 : 0,
			lastField: null,
			dirty: false,
			rules: null,
			builtFor: '',
		};

		const STRATEGY_LABELS = {
			id: __( 'ID: the unique key is an existing ID', 'total-import' ),
			slug: __( 'Slug', 'total-import' ),
			title: __( 'Title', 'total-import' ),
			name: __( 'Name', 'total-import' ),
			sku: __( 'SKU', 'total-import' ),
			email: __( 'Email', 'total-import' ),
			login: __( 'Username', 'total-import' ),
		};

		/* Steps. */

		function showStep( id, focus = true ) {
			state.step = id;
			const index = steps.indexOf( id );
			state.reached = Math.max( state.reached, index );
			$$( '.importright-step', form ).forEach( ( section ) => ( section.hidden = section.dataset.step !== id ) );
			$$( '[data-goto]' ).forEach( ( button ) => {
				const at = steps.indexOf( button.dataset.goto );
				button.disabled = at > state.reached;
				if ( at === index ) {
					button.setAttribute( 'aria-current', 'step' );
				} else {
					button.removeAttribute( 'aria-current' );
				}
				button.closest( 'li' ).classList.toggle( 'is-done', at < index );
			} );
			if ( 'mapping' === id ) {
				buildMapping();
				renderPreviewValues();
			}
			if ( 'matching' === id ) {
				buildMatching();
				renderPreviewValues();
			}
			if ( focus ) {
				$( '#importright-step-' + id + '-title' ).focus();
			}
		}

		async function next() {
			notice( 'info', '' );
			const index = steps.indexOf( state.step );
			try {
				if ( ! ( await validate( state.step ) ) ) {
					return;
				}
			} catch ( error ) {
				notice( 'error', error.message );
				return;
			}
			if ( index < steps.length - 1 ) {
				showStep( steps[ index + 1 ] );
			}
		}

		async function validate( step ) {
			if ( 'source' === step ) {
				if ( ! sourceReady() ) {
					notice( 'error', __( 'Choose a file, a URL or a path first.', 'total-import' ) );
					return false;
				}
				if ( ! $( '#importright-name' ).value.trim() ) {
					$( '#importright-name' ).value = defaultName();
				}
				if ( key() !== state.inspectedKey ) {
					await inspect( false );
				}
				if ( state.inspected && state.inspected.error ) {
					notice( 'error', state.inspected.error );
					return false;
				}
				return !! state.inspected;
			}
			if ( 'preview' === step && ! state.columns.length ) {
				notice( 'error', __( 'No columns were found in the file. Check the format settings.', 'total-import' ) );
				return false;
			}
			if ( 'matching' === step && ! /\{[^{}]+\}/.test( $( '#importright-unique-key' ).value ) ) {
				notice( 'error', __( 'Set a unique key that uses at least one column, such as {id}.', 'total-import' ) );
				$( '#importright-unique-key' ).focus();
				return false;
			}
			return true;
		}

		$$( '[data-goto]' ).forEach( ( button ) => button.addEventListener( 'click', () => showStep( button.dataset.goto ) ) );
		form.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '[data-nav]' );
			if ( ! button ) {
				return;
			}
			if ( 'back' === button.dataset.nav ) {
				showStep( steps[ Math.max( 0, steps.indexOf( state.step ) - 1 ) ] );
			} else {
				next();
			}
		} );
		form.addEventListener( 'submit', ( event ) => event.preventDefault() );
		form.addEventListener( 'input', () => ( state.dirty = true ) );
		form.addEventListener( 'input', plainHttpWarning );
		form.addEventListener( 'change', () => ( state.dirty = true ) );
		window.addEventListener( 'beforeunload', ( event ) => {
			if ( state.dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		/* Source. */

		function sourceType() {
			const checked = $( 'input[name="source_type"]:checked', form );
			return checked ? checked.value : 'upload';
		}

		function currentSource() {
			const type = sourceType();
			if ( 'upload' === type ) {
				return { type, file: state.source.file || '', original_name: state.source.original_name || '' };
			}
			if ( 'url' === type ) {
				const source = {
					type,
					url: $( '#importright-url' ).value.trim(),
					username: $( '#importright-url-username' ).value,
					password: $( '#importright-url-password' ).value,
				};
				if ( ! source.password && state.source.has_password ) {
					source.keep_password = 1;
				}
				return source;
			}
			return { type, path: ( $( '#importright-path' ) || { value: '' } ).value.trim() };
		}

		function currentFormat() {
			return {
				type: $( '#importright-format' ).value,
				delimiter: $( '#importright-delimiter' ).value,
				encoding: $( '#importright-encoding' ).value,
				has_header: $( '#importright-has-header' ).checked,
				sheet: $( '#importright-sheet' ).value,
				record_path: $( '#importright-record-path' ).value,
				json_path: $( '#importright-json-path' ).value.trim(),
			};
		}

		function key() {
			return JSON.stringify( [ currentSource(), currentFormat() ] );
		}

		function sourceReady() {
			const source = currentSource();
			return ( 'upload' === source.type && source.file ) || ( 'url' === source.type && source.url ) || ( 'path' === source.type && source.path );
		}

		function defaultName() {
			const source = currentSource();
			const raw = source.original_name || source.path || source.url || '';
			const base = raw.split( /[/?#]/ ).filter( Boolean ).pop() || __( 'Import', 'total-import' );
			return base.replace( /\.(csv|tsv|txt|xlsx|xml|json|zip|gz)(\.gz)?$/i, '' );
		}

		function statusFor( type ) {
			return $( '#importright-' + type + '-status' );
		}

		function showSourceRows() {
			const type = sourceType();
			$$( '[data-source]', form ).forEach( ( row ) => ( row.hidden = row.dataset.source !== type ) );
		}

		function effectiveFormat() {
			const chosen = $( '#importright-format' ).value;
			const format = 'auto' !== chosen ? chosen : state.inspected && state.inspected.format;
			return 'tsv' === format ? 'csv' : format || '';
		}

		function showFormatRows() {
			const format = effectiveFormat();
			$$( '[data-format]', form ).forEach( ( row ) => ( row.hidden = ! row.dataset.format.split( ' ' ).includes( format ) ) );
		}

		function setOptions( select, options, keep ) {
			const first = select.options[ 0 ];
			select.replaceChildren( first );
			options.forEach( ( [ value, label ] ) => select.append( el( 'option', { value, text: label } ) ) );
			if ( keep && ! options.some( ( [ value ] ) => value === keep ) ) {
				select.append( el( 'option', { value: keep, text: keep } ) );
			}
			select.value = keep || '';
		}

		async function inspect( refresh ) {
			const type = sourceType();
			const status = statusFor( type );
			const source = currentSource();
			if ( ! sourceReady() ) {
				return;
			}
			if ( status ) {
				status.textContent = 'url' === type ? __( 'Downloading…', 'total-import' ) : __( 'Reading the file…', 'total-import' );
			}
			const requestKey = key();
			let result;
			try {
				result = await call( 'inspect', { source, format: currentFormat(), refresh: refresh ? 1 : 0 } );
			} catch ( error ) {
				if ( status ) {
					status.textContent = '';
				}
				state.inspected = null;
				state.inspectedKey = '';
				throw error;
			}
			state.inspected = result;
			state.inspectedKey = requestKey;
			state.records = result.records || [];
			state.columns = result.columns || [];

			const formatNames = { csv: 'CSV', tsv: 'TSV', xlsx: 'Excel (XLSX)', xml: 'XML', json: 'JSON' };
			const facts = [ bytes( result.bytes ) ];
			if ( result.type ) {
				facts.push( result.type );
			}
			/* translators: %s: format name such as CSV */
			facts.push( sprintf( __( 'read as %s', 'total-import' ), formatNames[ result.format ] || result.format ) );
			if ( result.packed ) {
				facts.push( __( 'unpacked', 'total-import' ) );
			}
			/* translators: %s: number of columns */
			facts.push( sprintf( _n( '%s column', '%s columns', state.columns.length, 'total-import' ), number( state.columns.length ) ) );
			if ( status ) {
				status.textContent = result.error ? result.error : facts.join( ', ' );
				status.classList.toggle( 'is-error', !! result.error );
			}

			/* translators: %s: format name */
			$( '#importright-format-detected' ).textContent = 'auto' === $( '#importright-format' ).value ? sprintf( __( 'Detected: %s', 'total-import' ), formatNames[ result.format ] || result.format ) : '';
			const detected = result.detected || {};
			const delimiters = { ',': __( 'comma', 'total-import' ), ';': __( 'semicolon', 'total-import' ), '\t': __( 'tab', 'total-import' ), '|': __( 'pipe', 'total-import' ) };
			/* translators: %s: delimiter name */
			$( '#importright-delimiter-detected' ).textContent = detected.delimiter && ! $( '#importright-delimiter' ).value ? sprintf( __( 'Detected: %s', 'total-import' ), delimiters[ detected.delimiter ] || detected.delimiter ) : '';
			/* translators: %s: encoding name */
			$( '#importright-encoding-detected' ).textContent = detected.encoding && ! $( '#importright-encoding' ).value ? sprintf( __( 'Detected: %s', 'total-import' ), detected.encoding ) : '';

			setOptions( $( '#importright-sheet' ), ( result.sheets || [] ).map( ( name ) => [ name, name ] ), currentFormat().sheet );
			const elements = Object.entries( result.elements || {} ).map( ( [ path, count ] ) => [
				path,
				/* translators: 1: element path, 2: number of times it appears */
				sprintf( __( '%1$s (%2$s found)', 'total-import' ), path, number( count ) ),
			] );
			setOptions( $( '#importright-record-path' ), elements, currentFormat().record_path );
			if ( detected.record_path ) {
				/* translators: %s: element path */
				$( '#importright-record-path' ).options[ 0 ].textContent = sprintf( __( 'Detect (%s)', 'total-import' ), detected.record_path );
			}
			state.inspectedKey = key();
			showFormatRows();
			renderPreview();
			renderChips();
		}

		function renderPreview() {
			const result = state.inspected;
			const box = $( '#importright-preview-table' );
			const summary = $( '#importright-preview-summary' );
			if ( ! result ) {
				box.replaceChildren();
				summary.textContent = '';
				return;
			}
			if ( result.error ) {
				summary.textContent = '';
				box.replaceChildren( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: result.error } ) ] ) );
				return;
			}
			summary.textContent =
				sprintf(
					/* translators: 1: file name, 2: number of records shown */
					_n( '%1$s: showing the first record.', '%1$s: showing the first %2$s records.', state.records.length, 'total-import' ),
					result.name,
					number( state.records.length )
				) +
				( null !== result.total
					? ' ' +
					  sprintf(
							/* translators: %s: number of records */
							__( '%s records in total.', 'total-import' ),
							number( result.total )
					  )
					: '' );
			const head = el( 'tr', {}, [
				el( 'th', { scope: 'col', className: 'num', text: '#' } ),
				...state.columns.map( ( column ) => el( 'th', { scope: 'col', text: column } ) ),
			] );
			const rows = state.records.map( ( record, index ) =>
				el( 'tr', {}, [
					el( 'td', { className: 'num', text: String( index + 1 ) } ),
					...state.columns.map( ( column ) => el( 'td', {}, [ el( 'span', { className: 'importright-cell', text: clip( record[ column ] ?? '', 300 ) } ) ] ) ),
				] )
			);
			box.replaceChildren( el( 'table', { className: 'widefat striped importright-records' }, [ el( 'thead', {}, [ head ] ), el( 'tbody', {}, rows ) ] ) );

			const select = $( '#importright-preview-record' );
			const keep = select.value;
			select.replaceChildren(
				...state.records.map( ( record, index ) =>
					el( 'option', {
						value: String( index ),
						/* translators: %s: record number */
						text: sprintf( __( 'Record %s', 'total-import' ), index + 1 ),
					} )
				)
			);
			select.value = keep && Number( keep ) < state.records.length ? keep : '0';
		}

		function renderChips() {
			$$( '[data-chips]', form ).forEach( ( box ) => {
				const interactive = box.hasAttribute( 'data-insert' );
				box.replaceChildren(
					...state.columns.map( ( column ) => {
						const token = '{' + column + '}';
						if ( ! interactive ) {
							return el( 'code', { className: 'importright-chip', text: token } );
						}
						const chip = el( 'button', {
							type: 'button',
							className: 'importright-chip',
							draggable: 'true',
							'data-token': token,
							text: token,
							/* translators: %s: column reference such as {price} */
							'aria-label': sprintf( __( 'Insert %s', 'total-import' ), token ),
						} );
						return chip;
					} )
				);
				if ( interactive && state.columns.length ) {
					box.prepend( el( 'span', { className: 'importright-chips-label', id: 'importright-chips-label', text: __( 'Columns:', 'total-import' ) } ) );
				}
			} );
		}

		form.addEventListener( 'dragstart', ( event ) => {
			const chip = event.target.closest && event.target.closest( '.importright-chip[data-token]' );
			if ( chip ) {
				event.dataTransfer.setData( 'text/plain', chip.dataset.token );
				event.dataTransfer.effectAllowed = 'copy';
			}
		} );

		form.addEventListener( 'focusin', ( event ) => {
			if ( event.target.matches( '.importright-template' ) ) {
				state.lastField = event.target;
			}
		} );

		form.addEventListener( 'click', ( event ) => {
			const chip = event.target.closest( 'button.importright-chip' );
			if ( ! chip ) {
				return;
			}
			let field = state.lastField && document.body.contains( state.lastField ) && ! state.lastField.closest( '[hidden]' ) ? state.lastField : null;
			if ( ! field ) {
				field = $( '.importright-step:not([hidden]) .importright-template' );
			}
			if ( ! field ) {
				return;
			}
			const start = field.selectionStart ?? field.value.length;
			const end = field.selectionEnd ?? field.value.length;
			field.setRangeText( chip.dataset.token, start, end, 'end' );
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			field.focus();
		} );

		$$( 'input[name="source_type"]', form ).forEach( ( radio ) =>
			radio.addEventListener( 'change', () => {
				showSourceRows();
				state.inspectedKey = '';
			} )
		);

		$( '#importright-file' ).addEventListener( 'change', async ( event ) => {
			const file = event.target.files[ 0 ];
			const status = statusFor( 'upload' );
			if ( ! file ) {
				return;
			}
			if ( config.maxUpload && file.size > config.maxUpload ) {
				status.textContent = sprintf(
					/* translators: %s: maximum size */
					__( 'The file is larger than this site accepts (%s). Use a URL or a server path instead.', 'total-import' ),
					bytes( config.maxUpload )
				);
				status.classList.add( 'is-error' );
				return;
			}
			/* translators: %s: file name */
			status.textContent = sprintf( __( 'Uploading %s…', 'total-import' ), file.name );
			status.classList.remove( 'is-error' );
			try {
				const stored = await call( 'upload', { file } );
				state.source = { type: 'upload', file: stored.file, original_name: stored.original_name };
				if ( ! $( '#importright-name' ).value.trim() ) {
					$( '#importright-name' ).value = defaultName();
				}
				await inspect( false );
			} catch ( error ) {
				status.textContent = error.message;
				status.classList.add( 'is-error' );
			}
		} );

		// The fetcher only sends credentials back to the same scheme, host and port, so an http:// URL sends them in clear.
		function plainHttpWarning() {
			const url = $( '#importright-url' ).value.trim().toLowerCase();
			const credentials = $( '#importright-url-username' ).value || $( '#importright-url-password' ).value || state.source.has_password;
			$( '#importright-url-plain-http' ).hidden = ! ( url.startsWith( 'http://' ) && credentials );
		}

		$( '#importright-test-url' ).addEventListener( 'click', async () => {
			const status = statusFor( 'url' );
			status.classList.remove( 'is-error' );
			if ( ! $( '#importright-url' ).value.trim() ) {
				status.textContent = __( 'Enter a URL first.', 'total-import' );
				status.classList.add( 'is-error' );
				$( '#importright-url' ).focus();
				return;
			}
			try {
				await inspect( true );
			} catch ( error ) {
				status.textContent = error.message;
				status.classList.add( 'is-error' );
			}
		} );

		if ( $( '#importright-read-path' ) ) {
			$( '#importright-read-path' ).addEventListener( 'click', async () => {
				const status = statusFor( 'path' );
				status.classList.remove( 'is-error' );
				try {
					await inspect( false );
				} catch ( error ) {
					status.textContent = error.message;
					status.classList.add( 'is-error' );
				}
			} );
		}

		let formatTimer;
		[ '#importright-format', '#importright-delimiter', '#importright-encoding', '#importright-has-header', '#importright-sheet', '#importright-record-path', '#importright-json-path' ].forEach( ( selector ) =>
			$( selector ).addEventListener( 'change', () => {
				showFormatRows();
				clearTimeout( formatTimer );
				if ( ! sourceReady() ) {
					return;
				}
				formatTimer = setTimeout( () => inspect( false ).catch( ( error ) => notice( 'error', error.message ) ), 150 );
			} )
		);

		/* Target. */

		function targetType() {
			const checked = $( 'input[name="target_type"]:checked', form );
			return checked ? checked.value : 'post';
		}

		function showTargetRows() {
			const type = targetType();
			$$( '[data-target]', form ).forEach( ( row ) => ( row.hidden = ! row.dataset.target.split( ' ' ).includes( type ) ) );
		}

		$$( 'input[name="target_type"]', form ).forEach( ( radio ) => radio.addEventListener( 'change', showTargetRows ) );

		function currentTarget() {
			return applyFilters( 'importright.targetSettings', builtinTarget(), targetType() );
		}

		function builtinTarget() {
			const type = targetType();
			if ( 'post' === type ) {
				return { type, post_type: $( '#importright-post-type' ).value, default_status: $( '#importright-default-status' ).value };
			}
			if ( 'term' === type ) {
				return { type, taxonomy: $( '#importright-taxonomy' ).value };
			}
			if ( 'user' === type ) {
				return {
					type,
					default_role: $( '#importright-default-role' ).value,
					send_notification: $( '#importright-send-notification' ).checked,
					allow_privileged_roles: $( '#importright-allow-privileged' ).checked,
				};
			}
			if ( 'product' === type ) {
				return { type, default_status: $( '#importright-default-status' ).value, fast_price_stock: $( '#importright-fast-price-stock' ).checked };
			}
			return { type };
		}

		/* Mapping. */

		const PRODUCT_DATA = [ 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'weight', 'length', 'width', 'height' ];
		const LONG_FIELDS = [ 'content', 'description', 'short_description', 'excerpt' ];
		let fieldCount = 0;

		function templateField( label, path, value = '', help = '', long = false ) {
			const id = 'importright-map-' + ++fieldCount;
			const input = long
				? el( 'textarea', { id, className: 'large-text code importright-template', rows: '2', spellcheck: 'false', 'data-path': JSON.stringify( path ) } )
				: el( 'input', { type: 'text', id, className: 'large-text code importright-template', autocomplete: 'off', spellcheck: 'false', 'data-path': JSON.stringify( path ) } );
			input.value = value || '';
			const describedBy = [];
			const output = el( 'output', { className: 'importright-rendered', for: id, 'data-rendered': JSON.stringify( path ), id: id + '-value' } );
			describedBy.push( id + '-value' );
			let helpNode = null;
			if ( help ) {
				helpNode = el( 'p', { className: 'description', id: id + '-help', text: help } );
				describedBy.push( id + '-help' );
			}
			input.setAttribute( 'aria-describedby', describedBy.join( ' ' ) );
			return el( 'tr', {}, [ el( 'th', { scope: 'row' }, [ el( 'label', { for: id, text: label } ) ] ), el( 'td', {}, [ input, output, helpNode ] ) ] );
		}

		function choicesFor( type, name ) {
			const statuses = config.statuses.map( ( status ) => [ status.name, status.label ] );
			const open = [
				[ 'open', __( 'Open', 'total-import' ) ],
				[ 'closed', __( 'Closed', 'total-import' ) ],
			];
			const lists = {
				post: { status: statuses, comment_status: open, ping_status: open },
				product: {
					status: statuses,
					manage_stock: [
						[ 'yes', __( 'Yes', 'total-import' ) ],
						[ 'no', __( 'No', 'total-import' ) ],
					],
					stock_status: [
						[ 'instock', __( 'In stock', 'total-import' ) ],
						[ 'outofstock', __( 'Out of stock', 'total-import' ) ],
						[ 'onbackorder', __( 'On backorder', 'total-import' ) ],
					],
					backorders: [
						[ 'no', __( 'Do not allow', 'total-import' ) ],
						[ 'notify', __( 'Allow, but notify the customer', 'total-import' ) ],
						[ 'yes', __( 'Allow', 'total-import' ) ],
					],
				},
				user: { role: config.roles.map( ( role ) => [ role.name, role.label ] ) },
			};
			return ( lists[ type ] || {} )[ name ] || null;
		}

		/* A field with a fixed set of values: a select of those values, or "From a column" for a template. */
		function choiceField( label, path, value, choices ) {
			const row = templateField( label, path, value );
			const input = $( '.importright-template', row );
			const id = input.id;
			const wrap = el( 'div', { className: 'importright-choice-template' } );
			input.before( wrap );
			wrap.append( input, $( 'output', row ) );
			input.setAttribute(
				'aria-label',
				/* translators: %s: field name */
				sprintf( __( '%s: column or template', 'total-import' ), label )
			);
			const select = el( 'select', { id: id + '-choice', 'data-choice': '' }, [
				el( 'option', { value: '', text: __( 'Don\'t set', 'total-import' ) } ),
				...choices.map( ( [ key, text ] ) => el( 'option', { value: key, text } ) ),
				el( 'option', { value: '__column', text: __( 'From a column…', 'total-import' ) } ),
			] );
			const fixed = choices.some( ( [ key ] ) => key === ( value || '' ) );
			select.value = ! value ? '' : fixed ? value : '__column';
			wrap.hidden = '__column' !== select.value;
			$( 'label', row ).setAttribute( 'for', select.id );
			wrap.before( select );
			select.addEventListener( 'change', () => {
				const column = '__column' === select.value;
				wrap.hidden = ! column;
				if ( column ) {
					if ( choices.some( ( [ key ] ) => key === input.value ) ) {
						input.value = '';
					}
					input.focus();
				} else {
					input.value = select.value;
				}
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
			return row;
		}

		function fieldRow( type, name, label, value, long = false ) {
			const choices = choicesFor( type, name );
			return choices ? choiceField( label, [ name ], value, choices ) : templateField( label, [ name ], value, '', long );
		}

		function group( key, title, rows, intro = '' ) {
			return el( 'details', { className: 'importright-group', open: true, 'data-group': key }, [
				el( 'summary', {}, [ el( 'span', { className: 'importright-group-title', text: title } ) ] ),
				el( 'div', { className: 'importright-group-body' }, [ intro ? el( 'p', { className: 'description', text: intro } ) : null, el( 'table', { className: 'form-table', role: 'presentation' }, [ el( 'tbody', {}, rows ) ] ) ] ),
			] );
		}

		function repeater( kind, rows, addLabel, intro ) {
			const body = el( 'div', { className: 'importright-repeater', 'data-repeater': kind } );
			const add = el( 'button', { type: 'button', className: 'button', text: addLabel } );
			add.addEventListener( 'click', () => {
				const row = repeaterRow( kind, '', {} );
				body.append( row );
				$( 'input', row ).focus();
			} );
			rows.forEach( ( [ name, value ] ) => body.append( repeaterRow( kind, name, value ) ) );
			return el( 'div', {}, [ intro ? el( 'p', { className: 'description', text: intro } ) : null, body, el( 'p', {}, [ add ] ) ] );
		}

		function repeaterRow( kind, name, value ) {
			const id = 'importright-map-' + ++fieldCount;
			const settings = 'object' === typeof value && null !== value ? value : { values: value };
			const nameInput = el( 'input', { type: 'text', id: id + '-name', className: 'code', autocomplete: 'off', spellcheck: 'false', 'data-role': 'name', list: 'attributes' === kind ? 'importright-attribute-names' : null } );
			nameInput.value = name || '';
			const template = 'meta' === kind ? value : settings.values;
			const valueInput = el( 'input', { type: 'text', id, className: 'code importright-template', autocomplete: 'off', spellcheck: 'false', 'data-role': 'value', 'aria-describedby': id + '-value' } );
			valueInput.value = template || '';
			const output = el( 'output', { className: 'importright-rendered', for: id, id: id + '-value', 'data-row-rendered': '' } );
			const remove = el( 'button', { type: 'button', className: 'button-link button-link-delete', text: __( 'Remove', 'total-import' ) } );
			remove.addEventListener( 'click', () => {
				row.remove();
				state.dirty = true;
				schedulePreview();
			} );
			const parts = [
				el( 'div', { className: 'importright-repeater-name' }, [
					el( 'label', { for: id + '-name', text: 'meta' === kind ? __( 'Field key', 'total-import' ) : __( 'Attribute', 'total-import' ) } ),
					nameInput,
				] ),
				el( 'div', { className: 'importright-repeater-value' }, [ el( 'label', { for: id, text: 'meta' === kind ? __( 'Value', 'total-import' ) : __( 'Values', 'total-import' ) } ), valueInput, output ] ),
			];
			if ( 'attributes' === kind ) {
				const sep = el( 'input', { type: 'text', id: id + '-sep', className: 'small-text code', 'data-role': 'separator', autocomplete: 'off' } );
				sep.value = settings.separator ?? '|';
				const visible = el( 'input', { type: 'checkbox', 'data-role': 'visible' } );
				visible.checked = '0' !== String( settings.visible ?? '1' );
				parts.push(
					el( 'div', { className: 'importright-repeater-options' }, [
						el( 'label', { for: id + '-sep', text: __( 'Separator', 'total-import' ) } ),
						sep,
						el( 'label', {}, [ visible, document.createTextNode( ' ' + __( 'Visible on the product page', 'total-import' ) ) ] ),
					] )
				);
			}
			parts.push( el( 'div', { className: 'importright-repeater-remove' }, [ remove ] ) );
			const row = el( 'div', { className: 'importright-repeater-row' }, parts );
			return row;
		}

		function taxonomyRows( taxonomies, values ) {
			return taxonomies.map( ( taxonomy ) => {
				const settings = values[ taxonomy.name ] || {};
				const terms = 'string' === typeof settings ? settings : settings.terms || '';
				const row = templateField(
					taxonomy.label,
					[ 'taxonomies', taxonomy.name, 'terms' ],
					terms,
					taxonomy.hierarchical ? __( 'Separate terms with the separator; write a path such as Parent > Child for a child term.', 'total-import' ) : ''
				);
				const id = 'importright-tax-' + taxonomy.name;
				const sep = el( 'input', { type: 'text', id: id + '-sep', className: 'small-text code', 'data-tax': taxonomy.name, 'data-option': 'separator', autocomplete: 'off' } );
				sep.value = settings.separator ?? ',';
				const options = [ el( 'label', { for: id + '-sep', text: __( 'Separator', 'total-import' ) } ), sep ];
				if ( taxonomy.hierarchical ) {
					const hierarchy = el( 'input', { type: 'text', id: id + '-hier', className: 'small-text code', 'data-tax': taxonomy.name, 'data-option': 'hierarchy', autocomplete: 'off' } );
					hierarchy.value = settings.hierarchy ?? '>';
					options.push( el( 'label', { for: id + '-hier', text: __( 'Parent > child', 'total-import' ) } ), hierarchy );
				}
				const mode = el( 'select', { id: id + '-mode', 'data-tax': taxonomy.name, 'data-option': 'mode' }, [
					el( 'option', { value: 'replace', text: __( 'Replace existing terms', 'total-import' ) } ),
					el( 'option', { value: 'append', text: __( 'Add to existing terms', 'total-import' ) } ),
				] );
				mode.value = settings.mode || 'replace';
				options.push( el( 'label', { for: id + '-mode', className: 'screen-reader-text', text: __( 'On update', 'total-import' ) } ), mode );
				$( 'td', row ).append( el( 'div', { className: 'importright-field-options' }, options ) );
				return row;
			} );
		}

		function taxonomiesFor( target ) {
			if ( 'product' === target.type ) {
				return config.productTaxonomies;
			}
			const type = config.postTypes.find( ( item ) => item.name === target.post_type );
			return type ? type.taxonomies : [];
		}

		function buildMapping( force = false ) {
			const target = currentTarget();
			const signature = JSON.stringify( [ target.type, target.post_type || '' ] );
			if ( ! force && signature === state.builtFor ) {
				return;
			}
			const mapping = state.builtFor ? collectMapping() : ( data.definition && data.definition.mapping ) || {};
			const previousType = state.builtFor ? JSON.parse( state.builtFor )[ 0 ] : target.type;
			if ( previousType !== target.type ) {
				const keep = applyFilters( 'importright.keepMappingKeys', [ 'meta', 'taxonomies', 'images', 'seo' ] );
				Object.keys( mapping ).forEach( ( key ) => ! keep.includes( key ) && delete mapping[ key ] );
			}
			state.builtFor = signature;

			const info = config.targets[ target.type ];
			const handlers = info.handlers;
			const fields = Object.entries( info.fields );
			const core = 'product' === target.type ? fields.filter( ( [ name ] ) => ! PRODUCT_DATA.includes( name ) ) : fields;
			const product = 'product' === target.type ? fields.filter( ( [ name ] ) => PRODUCT_DATA.includes( name ) ) : [];
			let groups = [];

			groups.push( group( 'core', __( 'Core fields', 'total-import' ), core.map( ( [ name, label ] ) => fieldRow( target.type, name, label, mapping[ name ], LONG_FIELDS.includes( name ) ) ) ) );

			if ( product.length ) {
				const box = group( 'product', __( 'Product data', 'total-import' ), product.map( ( [ name, label ] ) => fieldRow( target.type, name, label, mapping[ name ] ) ) );
				if ( handlers.includes( 'attributes' ) ) {
					$( '.importright-group-body', box ).append(
						el( 'h4', { text: __( 'Attributes', 'total-import' ) } ),
						repeater( 'attributes', Object.entries( mapping.attributes || {} ), __( 'Add attribute', 'total-import' ), __( 'Use a global attribute (such as pa_color) or any name for a custom one. Separate values with the separator.', 'total-import' ) )
					);
				}
				groups.push( box );
			}

			if ( handlers.includes( 'taxonomies' ) ) {
				const taxonomies = taxonomiesFor( target );
				if ( taxonomies.length ) {
					groups.push( group( 'taxonomies', __( 'Taxonomies', 'total-import' ), taxonomyRows( taxonomies, mapping.taxonomies || {} ) ) );
				}
			}

			if ( handlers.includes( 'images' ) ) {
				const images = mapping.images || {};
				const featured = 'string' === typeof images.featured ? { source: images.featured } : images.featured || {};
				const gallery = 'string' === typeof images.gallery ? { source: images.gallery } : images.gallery || {};
				const rows = [
					templateField( __( 'Featured image', 'total-import' ), [ 'images', 'featured', 'source' ], featured.source, __( 'An image URL, a Media Library file name or an attachment ID.', 'total-import' ) ),
					templateField( __( 'Featured image alt text', 'total-import' ), [ 'images', 'featured', 'alt' ], featured.alt ),
					templateField( __( 'Featured image title', 'total-import' ), [ 'images', 'featured', 'title' ], featured.title ),
					templateField( __( 'Featured image caption', 'total-import' ), [ 'images', 'featured', 'caption' ], featured.caption ),
					templateField( __( 'Gallery images', 'total-import' ), [ 'images', 'gallery', 'source' ], gallery.source, __( 'Several images separated by the gallery separator.', 'total-import' ) ),
				];
				const sepId = 'importright-gallery-sep';
				const sep = el( 'input', { type: 'text', id: sepId, className: 'small-text code', 'data-gallery': 'separator', autocomplete: 'off' } );
				sep.value = gallery.separator ?? '|';
				rows.push( el( 'tr', {}, [ el( 'th', { scope: 'row' }, [ el( 'label', { for: sepId, text: __( 'Gallery separator', 'total-import' ) } ) ] ), el( 'td', {}, [ sep ] ) ] ) );
				rows.push(
					templateField( __( 'Gallery alt text', 'total-import' ), [ 'images', 'gallery', 'alt' ], gallery.alt, __( 'One per image, with the same separator, or one for all.', 'total-import' ) ),
					templateField( __( 'Gallery titles', 'total-import' ), [ 'images', 'gallery', 'title' ], gallery.title ),
					templateField( __( 'Gallery captions', 'total-import' ), [ 'images', 'gallery', 'caption' ], gallery.caption )
				);
				const box = group( 'images', __( 'Images', 'total-import' ), rows );
				box.open = !! ( featured.source || gallery.source );
				groups.push( box );
			}

			if ( handlers.includes( 'meta' ) ) {
				const box = el( 'details', { className: 'importright-group', open: true, 'data-group': 'meta' }, [
					el( 'summary', {}, [ el( 'span', { className: 'importright-group-title', text: __( 'Custom fields', 'total-import' ) } ) ] ),
					el( 'div', { className: 'importright-group-body' }, [
						repeater( 'meta', Object.entries( mapping.meta || {} ), __( 'Add custom field', 'total-import' ), __( 'Any meta key, such as _price or a field created by ACF or Meta Box.', 'total-import' ) ),
					] ),
				] );
				groups.push( box );
			}

			if ( handlers.includes( 'seo' ) ) {
				const seo = mapping.seo || {};
				const active = [ config.seo.yoast ? 'Yoast SEO' : '', config.seo.rankmath ? 'Rank Math' : '' ].filter( Boolean );
				const intro = active.length
					? /* translators: %s: SEO plugin names */
					  sprintf( __( 'Written for %s.', 'total-import' ), active.join( ', ' ) )
					: __( 'Yoast SEO and Rank Math are supported. Neither is active, so these values are only written once one is.', 'total-import' );
				const box = group(
					'seo',
					__( 'SEO', 'total-import' ),
					[ templateField( __( 'SEO title', 'total-import' ), [ 'seo', 'title' ], seo.title ), templateField( __( 'Meta description', 'total-import' ), [ 'seo', 'description' ], seo.description ) ],
					intro
				);
				box.open = !! ( seo.title || seo.description );
				groups.push( box );
			}

			const datalist = el(
				'datalist',
				{ id: 'importright-attribute-names' },
				config.attributes.map( ( attribute ) => el( 'option', { value: attribute.name, text: attribute.label } ) )
			);
			groups = applyFilters( 'importright.mappingGroups', groups, { target, mapping, helpers: { el, group, fieldRow, templateField, repeater } } );
			$( '#importright-mapping' ).replaceChildren( ...groups, datalist );
		}

		function collectMapping() {
			const mapping = {};
			const root = $( '#importright-mapping' );
			$$( '.importright-template[data-path]', root ).forEach( ( input ) => {
				const path = JSON.parse( input.dataset.path );
				if ( '' === input.value.trim() && 1 === path.length ) {
					return;
				}
				let node = mapping;
				path.slice( 0, -1 ).forEach( ( part ) => ( node = node[ part ] = node[ part ] || {} ) );
				node[ path[ path.length - 1 ] ] = input.value;
			} );

			if ( mapping.taxonomies ) {
				Object.keys( mapping.taxonomies ).forEach( ( taxonomy ) => {
					const settings = mapping.taxonomies[ taxonomy ];
					if ( '' === settings.terms.trim() ) {
						delete mapping.taxonomies[ taxonomy ];
						return;
					}
					$$( '[data-tax="' + CSS.escape( taxonomy ) + '"]', root ).forEach( ( input ) => ( settings[ input.dataset.option ] = input.value ) );
					settings.create = $( '#importright-create-terms' ).checked ? '1' : '0';
				} );
				if ( ! Object.keys( mapping.taxonomies ).length ) {
					delete mapping.taxonomies;
				}
			}

			if ( mapping.images ) {
				const images = {};
				[ 'featured', 'gallery' ].forEach( ( part ) => {
					const settings = mapping.images[ part ];
					if ( settings && '' !== settings.source.trim() ) {
						images[ part ] = settings;
					}
				} );
				if ( images.gallery ) {
					images.gallery.separator = $( '[data-gallery="separator"]', root ).value;
				}
				if ( images.featured || images.gallery ) {
					images.match_filename = $( '#importright-match-filename' ).checked ? '1' : '0';
					mapping.images = images;
				} else {
					delete mapping.images;
				}
			}

			if ( mapping.seo ) {
				Object.keys( mapping.seo ).forEach( ( key ) => '' === mapping.seo[ key ].trim() && delete mapping.seo[ key ] );
				if ( ! Object.keys( mapping.seo ).length ) {
					delete mapping.seo;
				}
			}

			$$( '[data-repeater]', root ).forEach( ( box ) => {
				const kind = box.dataset.repeater;
				const section = {};
				$$( '.importright-repeater-row', box ).forEach( ( row ) => {
					const name = $( '[data-role="name"]', row ).value.trim();
					const value = $( '[data-role="value"]', row ).value;
					if ( ! name ) {
						return;
					}
					if ( 'meta' === kind ) {
						section[ name ] = value;
					} else if ( '' !== value.trim() ) {
						section[ name ] = {
							values: value,
							separator: $( '[data-role="separator"]', row ).value,
							visible: $( '[data-role="visible"]', row ).checked ? '1' : '0',
							create: $( '#importright-create-values' ).checked ? '1' : '0',
						};
					}
				} );
				if ( Object.keys( section ).length ) {
					mapping[ kind ] = section;
				}
			} );
			return applyFilters( 'importright.collectMapping', mapping );
		}

		let previewTimer;
		function schedulePreview() {
			clearTimeout( previewTimer );
			previewTimer = setTimeout( renderPreviewValues, 250 );
		}

		form.addEventListener( 'input', ( event ) => {
			if ( event.target.closest( '#importright-mapping, #importright-step-matching' ) ) {
				schedulePreview();
			}
		} );
		$( '#importright-preview-record' ).addEventListener( 'change', renderPreviewValues );

		async function renderPreviewValues() {
			const record = state.records[ Number( $( '#importright-preview-record' ).value || 0 ) ];
			const outputs = $$( '.importright-rendered', form );
			if ( ! record ) {
				outputs.forEach( ( output ) => ( output.textContent = '' ) );
				return;
			}
			const mapping = collectMapping();
			let result;
			try {
				result = await call( 'render', { mapping, unique_key: $( '#importright-unique-key' ).value, record } );
			} catch ( error ) {
				return;
			}
			const lookup = ( path ) => path.reduce( ( node, part ) => ( node && 'object' === typeof node ? node[ part ] : undefined ), result.values );
			outputs.forEach( ( output ) => {
				let value;
				if ( output.hasAttribute( 'data-row-rendered' ) ) {
					const row = output.closest( '.importright-repeater-row' );
					const kind = output.closest( '[data-repeater]' ).dataset.repeater;
					const name = $( '[data-role="name"]', row ).value.trim();
					value = lookup( 'meta' === kind ? [ 'meta', name ] : [ 'attributes', name, 'values' ] );
				} else if ( 'unique_key' === output.dataset.rendered ) {
					value = result.key;
				} else {
					value = lookup( JSON.parse( output.dataset.rendered ) );
				}
				const input = document.getElementById( output.getAttribute( 'for' ) );
				const empty = ! input || '' === input.value.trim();
				output.classList.toggle( 'is-empty', ! empty && ( undefined === value || '' === value ) );
				if ( empty ) {
					output.textContent = '';
				} else if ( undefined === value || '' === value ) {
					output.textContent = __( 'Empty for this record', 'total-import' );
				} else {
					output.textContent = clip( value, 300 );
				}
			} );
		}

		/* Matching and update rules. */

		function buildMatching() {
			const target = currentTarget();
			const info = config.targets[ target.type ];
			const select = $( '#importright-match-existing' );
			const keep = select.dataset.value ?? select.value;
			delete select.dataset.value;
			select.replaceChildren(
				el( 'option', { value: '', text: __( 'Don\'t match existing content', 'total-import' ) } ),
				...info.strategies.map( ( strategy ) => el( 'option', { value: strategy, text: STRATEGY_LABELS[ strategy ] || strategy } ) ),
				el( 'option', { value: 'meta', text: __( 'Custom field: its value equals the unique key', 'total-import' ) } )
			);
			if ( keep.startsWith( 'meta:' ) ) {
				select.value = 'meta';
				$( '#importright-match-meta' ).value = keep.slice( 5 );
			} else {
				select.value = info.strategies.includes( keep ) || 'meta' === keep ? keep : '';
			}
			$( '#importright-match-meta' ).hidden = 'meta' !== select.value;

			$$( '[data-handler]', form ).forEach( ( node ) => ( node.hidden = ! node.dataset.handler.split( ' ' ).some( ( handler ) => info.handlers.includes( handler ) ) ) );

			if ( ! $( '#importright-unique-key' ).value.trim() && state.columns.length ) {
				const guess = state.columns.find( ( column ) => /^(id|sku|guid|@id|ean|code|email|slug)$/i.test( column ) ) || state.columns[ 0 ];
				$( '#importright-unique-key' ).value = '{' + guess + '}';
			}
			buildRules();
		}

		$( '#importright-match-existing' ).addEventListener( 'change', ( event ) => {
			$( '#importright-match-meta' ).hidden = 'meta' !== event.target.value;
		} );

		function ruleLabel( key ) {
			const info = config.targets[ targetType() ];
			return info.fields[ key ] || key;
		}

		function buildRules() {
			const box = $( '#importright-update-rules' );
			const previous = currentRules( true );
			const mapping = collectMapping();
			const items = [];
			const sections = { meta: __( 'Custom field', 'total-import' ), taxonomies: __( 'Taxonomy', 'total-import' ), images: __( 'Image', 'total-import' ), seo: __( 'SEO', 'total-import' ), attributes: __( 'Attribute', 'total-import' ) };
			Object.keys( mapping ).forEach( ( key ) => {
				if ( ! sections[ key ] ) {
					items.push( { rule: key, sub: '', label: ruleLabel( key ) } );
					return;
				}
				const subs = 'images' === key ? [ 'featured', 'gallery' ].filter( ( part ) => mapping.images[ part ] ) : 'seo' === key ? Object.keys( mapping.seo ).filter( ( part ) => 'plugins' !== part ) : Object.keys( mapping[ key ] );
				subs.forEach( ( sub ) => items.push( { rule: key, sub, label: sections[ key ] + ': ' + sub } ) );
			} );

			const legend = $( 'legend', box );
			box.replaceChildren( legend );
			if ( ! items.length ) {
				box.append( el( 'p', { className: 'description', text: __( 'Map some fields first.', 'total-import' ) } ) );
				return;
			}
			items.forEach( ( item ) => {
				const input = el( 'input', { type: 'checkbox', 'data-rule': item.rule, 'data-sub': item.sub } );
				input.checked = isAllowed( previous, item.rule, item.sub );
				box.append( el( 'label', { className: 'importright-rule' }, [ input, document.createTextNode( ' ' + item.label ) ] ) );
			} );
		}

		function isAllowed( rules, rule, sub ) {
			if ( ! rules || ! ( rule in rules ) ) {
				return true;
			}
			const value = rules[ rule ];
			if ( Array.isArray( value ) ) {
				return sub ? value.includes( sub ) : value.length > 0;
			}
			return false !== value;
		}

		function currentRules( fallback = false ) {
			const inputs = $$( '#importright-update-rules [data-rule]' );
			if ( ! inputs.length ) {
				return fallback ? state.rules || ( data.definition && data.definition.update ) || {} : {};
			}
			const rules = {};
			const subs = {};
			inputs.forEach( ( input ) => {
				const rule = input.dataset.rule;
				if ( ! input.dataset.sub ) {
					rules[ rule ] = input.checked;
					return;
				}
				subs[ rule ] = subs[ rule ] || { all: [], on: [] };
				subs[ rule ].all.push( input.dataset.sub );
				if ( input.checked ) {
					subs[ rule ].on.push( input.dataset.sub );
				}
			} );
			Object.entries( subs ).forEach( ( [ rule, list ] ) => {
				rules[ rule ] = list.on.length === list.all.length ? true : list.on.length ? list.on : false;
			} );
			state.rules = rules;
			return rules;
		}

		/* Save. */

		function definition() {
			const select = $( '#importright-match-existing' );
			let match = select.value;
			if ( 'meta' === match ) {
				const metaKey = $( '#importright-match-meta' ).value.trim();
				match = metaKey ? 'meta:' + metaKey : '';
			}
			return applyFilters( 'importright.definition', {
				name: $( '#importright-name' ).value.trim() || defaultName(),
				source: currentSource(),
				format: currentFormat(),
				target: currentTarget(),
				mapping: collectMapping(),
				matching: { unique_key: $( '#importright-unique-key' ).value, mode: $( 'input[name="mode"]:checked', form ).value, match_existing: match },
				update: currentRules( true ),
				options: { batch_size: Number( $( '#importright-batch-size' ).value ) || 100, skip_unchanged: $( '#importright-skip-unchanged' ).checked },
			} );
		}

		async function save() {
			const status = $( '#importright-save-status' );
			const button = $( '#importright-save' );
			if ( 'matching' !== state.step && ! $( '#importright-update-rules [data-rule]' ) ) {
				buildMapping();
				buildMatching();
			}
			button.disabled = true;
			status.textContent = __( 'Saving…', 'total-import' );
			try {
				const saved = await call( 'save', { import_id: state.importId, definition: definition() } );
				state.importId = saved.import_id;
				state.dirty = false;
				if ( saved.edit_url && window.history.replaceState ) {
					window.history.replaceState( null, '', saved.edit_url );
				}
				/* translators: %s: import name */
				$( '.importright-wizard h1' ).textContent = sprintf( __( 'Edit import: %s', 'total-import' ), saved.name );
				status.textContent = __( 'Saved.', 'total-import' );
				return true;
			} catch ( error ) {
				status.textContent = '';
				notice( 'error', error.message );
				if ( 'importright_password' === error.code ) {
					state.source.has_password = false;
					const password = $( '#importright-url-password' );
					password.placeholder = '';
					password.closest( 'details' ).open = true;
					showStep( 'source', false );
					password.focus();
					return false;
				}
				$( '#importright-notice' ).scrollIntoView( { block: 'nearest' } );
				return false;
			} finally {
				button.disabled = false;
			}
		}

		$( '#importright-save' ).addEventListener( 'click', save );

		runPanel( $( '#importright-run' ), {
			importId: () => state.importId,
			beforeStart: async () => ( state.dirty || ! state.importId ? save() : true ),
		} );

		/* Fill from a saved definition. */

		function fill( saved ) {
			$( '#importright-name' ).value = saved.name || '';
			const source = saved.source || {};
			const radio = $( 'input[name="source_type"][value="' + source.type + '"]', form );
			if ( radio ) {
				radio.checked = true;
			}
			state.source = Object.assign( { type: 'upload' }, source );
			if ( 'upload' === source.type ) {
				/* translators: %s: file name */
				statusFor( 'upload' ).textContent = sprintf( __( 'Current file: %s. Choose another file to replace it.', 'total-import' ), source.original_name || source.file );
			} else if ( 'url' === source.type ) {
				$( '#importright-url' ).value = source.url || '';
				$( '#importright-url-username' ).value = source.username || '';
				if ( source.has_password ) {
					$( '#importright-url-password' ).placeholder = __( 'Saved; type to replace', 'total-import' );
				}
				plainHttpWarning();
			} else if ( 'path' === source.type && $( '#importright-path' ) ) {
				$( '#importright-path' ).value = source.path || '';
			}

			const format = saved.format || {};
			$( '#importright-format' ).value = format.type || 'auto';
			$( '#importright-delimiter' ).value = format.delimiter || '';
			$( '#importright-encoding' ).value = format.encoding || '';
			$( '#importright-has-header' ).checked = false !== format.has_header;
			setOptions( $( '#importright-sheet' ), [], format.sheet || '' );
			setOptions( $( '#importright-record-path' ), [], format.record_path || '' );
			$( '#importright-json-path' ).value = format.json_path || '';

			const target = saved.target || {};
			const targetRadio = $( 'input[name="target_type"][value="' + target.type + '"]', form );
			if ( targetRadio ) {
				targetRadio.checked = true;
			}
			if ( target.post_type ) {
				$( '#importright-post-type' ).value = target.post_type;
			}
			if ( target.default_status ) {
				$( '#importright-default-status' ).value = target.default_status;
			}
			if ( target.taxonomy ) {
				$( '#importright-taxonomy' ).value = target.taxonomy;
			}
			if ( target.default_role ) {
				$( '#importright-default-role' ).value = target.default_role;
			}
			$( '#importright-send-notification' ).checked = !! target.send_notification;
			$( '#importright-allow-privileged' ).checked = !! target.allow_privileged_roles;
			$( '#importright-fast-price-stock' ).checked = false !== target.fast_price_stock;

			const matching = saved.matching || {};
			$( '#importright-unique-key' ).value = matching.unique_key || '';
			const mode = $( 'input[name="mode"][value="' + ( matching.mode || 'create_update' ) + '"]', form );
			if ( mode ) {
				mode.checked = true;
			}
			$( '#importright-match-existing' ).dataset.value = matching.match_existing || '';
			const options = saved.options || {};
			$( '#importright-batch-size' ).value = options.batch_size || 100;
			$( '#importright-skip-unchanged' ).checked = false !== options.skip_unchanged;

			const mapping = saved.mapping || {};
			const flags = ( section ) => Object.values( mapping[ section ] || {} ).map( ( item ) => item && item.create );
			$( '#importright-create-terms' ).checked = ! flags( 'taxonomies' ).includes( '0' );
			$( '#importright-create-values' ).checked = ! flags( 'attributes' ).includes( '0' );
			$( '#importright-match-filename' ).checked = '0' !== ( mapping.images || {} ).match_filename;
			state.rules = saved.update || {};

			buildMapping();
			buildMatching();
			doAction( 'importright.fill', saved );
		}

		showSourceRows();
		showTargetRows();
		showFormatRows();
		if ( data.definition ) {
			fill( data.definition );
			showSourceRows();
			showTargetRows();
			inspect( false ).catch( ( error ) => notice( 'warning', error.message ) );
		}
		state.dirty = false;
		showStep( steps[ 0 ], false );
	}

	/* History: one run. Undo with a dry-run check first, and taking over a stalled run. */

	function runDetail( root ) {
		const runId = Number( root.dataset.run );
		const OUTCOMES = {
			restored: __( 'Restored', 'total-import' ),
			deleted: __( 'Trashed or deleted', 'total-import' ),
			kept: __( 'Kept (still in use)', 'total-import' ),
			skipped: __( 'Skipped (edited since)', 'total-import' ),
			gone: __( 'Already gone', 'total-import' ),
			failed: __( 'Failed', 'total-import' ),
		};
		const PLANNED = {
			restored: __( 'Will be restored', 'total-import' ),
			deleted: __( 'Will be trashed or deleted', 'total-import' ),
			kept: __( 'Will be kept (still in use)', 'total-import' ),
			skipped: __( 'Will be skipped (edited since)', 'total-import' ),
			gone: __( 'Already gone', 'total-import' ),
			failed: __( 'Can\'t be undone', 'total-import' ),
		};
		const ACTIONS = {
			restored: __( 'Restore', 'total-import' ),
			deleted: __( 'Trash or delete', 'total-import' ),
			kept: __( 'Keep, still in use', 'total-import' ),
			skipped: __( 'Skip, edited since', 'total-import' ),
			gone: __( 'Already gone', 'total-import' ),
			failed: __( 'Can\'t undo', 'total-import' ),
		};
		const TYPES = {
			post: __( 'Post', 'total-import' ),
			product: __( 'Product', 'total-import' ),
			term: __( 'Term', 'total-import' ),
			user: __( 'User', 'total-import' ),
			attachment: __( 'Image', 'total-import' ),
		};
		const CHANGES = {
			created: __( 'Created by the run', 'total-import' ),
			updated: __( 'Updated by the run', 'total-import' ),
			added: __( 'Added along the way', 'total-import' ),
		};
		const box = $( '[data-undo]', root );
		const previewButton = $( '[data-undo-preview]', root );

		function countList( counts, labels ) {
			return el(
				'dl',
				{ className: 'importright-counts-list' },
				Object.entries( labels )
					.filter( ( [ key ] ) => counts[ key ] )
					.map( ( [ key, label ] ) => el( 'div', { className: 'importright-count importright-count-' + key }, [ el( 'dt', { text: label } ), el( 'dd', { text: number( counts[ key ] ) } ) ] ) )
			);
		}

		function itemTable( items, labels, caption ) {
			const rows = items.map( ( item ) =>
				el( 'tr', {}, [
					el( 'td', { 'data-label': __( 'Item', 'total-import' ) }, [
						el( 'span', { text: item.title || '#' + item.object_id } ),
						el( 'span', { className: 'importright-muted', text: ' ' + ( TYPES[ item.object_type ] || item.object_type ) + ' #' + item.object_id } ),
					] ),
					el( 'td', { 'data-label': __( 'What the run did', 'total-import' ) }, [
						el( 'span', { text: CHANGES[ item.action ] || item.action } ),
						item.fields && item.fields.length ? el( 'span', { className: 'importright-field-name', text: ' ' + item.fields.join( ', ' ) } ) : null,
					] ),
					el( 'td', { 'data-label': __( 'Undo', 'total-import' ) }, [
						el( 'span', { className: 'importright-status importright-outcome-' + item.status, text: labels[ item.status ] || item.status } ),
						item.note ? el( 'span', { className: 'importright-muted', text: ' ' + item.note } ) : null,
					] ),
				] )
			);
			return el( 'div', { className: 'importright-scroll', tabindex: '0', role: 'region', 'aria-label': caption }, [
				el( 'table', { className: 'widefat striped importright-undo-items importright-stack' }, [
					el( 'thead', {}, [
						el( 'tr', {}, [
							el( 'th', { scope: 'col', text: __( 'Item', 'total-import' ) } ),
							el( 'th', { scope: 'col', text: __( 'What the run did', 'total-import' ) } ),
							el( 'th', { scope: 'col', text: __( 'Undo', 'total-import' ) } ),
						] ),
					] ),
					el( 'tbody', {}, rows ),
				] ),
			] );
		}

		function fail( message ) {
			box.append( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: message } ) ] ) );
		}

		async function preview() {
			previewButton.disabled = true;
			box.hidden = false;
			box.replaceChildren( el( 'p', { role: 'status', text: __( 'Checking every change this run made…', 'total-import' ) } ) );
			let result;
			try {
				result = await call( 'rollback_preview', { run_id: runId } );
			} catch ( error ) {
				box.replaceChildren();
				fail( error.message );
				previewButton.disabled = false;
				return;
			}
			const nodes = [ el( 'h3', { text: __( 'What undo would do', 'total-import' ) } ), countList( result.counts, PLANNED ) ];
			if ( result.counts.skipped ) {
				nodes.push( el( 'p', { text: __( 'Skipped items were changed after this run, by hand or by another import. Undo leaves them as they are now.', 'total-import' ) } ) );
			}
			if ( result.items.length ) {
				nodes.push(
					el( 'p', {
						text:
							result.items.length < result.total
								? sprintf(
										/* translators: 1: items shown, 2: total items */
										__( 'The first %1$s of %2$s items:', 'total-import' ),
										number( result.items.length ),
										number( result.total )
								  )
								: __( 'Every item:', 'total-import' ),
					} ),
					itemTable( result.items, ACTIONS, __( 'What undo would do', 'total-import' ) )
				);
			}
			const confirm = el( 'button', { type: 'button', className: 'button button-primary', text: __( 'Undo this run', 'total-import' ) } );
			const cancel = el( 'button', { type: 'button', className: 'button', text: __( 'Cancel', 'total-import' ) } );
			confirm.addEventListener( 'click', () => undo( result.total ) );
			cancel.addEventListener( 'click', () => {
				box.hidden = true;
				box.replaceChildren();
				previewButton.disabled = false;
				previewButton.focus();
			} );
			nodes.push( el( 'p', { className: 'importright-run-links' }, [ confirm, cancel ] ) );
			box.replaceChildren( ...nodes );
			box.focus();
		}

		async function undo( total ) {
			const bar = el( 'progress', { id: 'importright-undo-bar', max: '100' } );
			const status = el( 'p', { className: 'importright-progress-status', role: 'status', 'aria-live': 'polite', text: __( 'Starting the undo…', 'total-import' ) } );
			box.replaceChildren(
				el( 'div', { className: 'importright-progress' }, [ el( 'label', { for: 'importright-undo-bar', className: 'importright-progress-label', text: __( 'Undo progress', 'total-import' ) } ), bar, status ] )
			);
			const show = ( counts ) => {
				const left = counts.pending || 0;
				const all = Math.max( total, Object.values( counts ).reduce( ( sum, value ) => sum + Number( value || 0 ), 0 ) );
				bar.value = all ? Math.round( ( ( all - left ) / all ) * 100 ) : 0;
				status.textContent = sprintf(
					/* translators: 1: items done, 2: total items */
					__( '%1$s of %2$s items done.', 'total-import' ),
					number( all - left ),
					number( all )
				);
			};
			let step;
			try {
				step = await call( 'rollback_start', { run_id: runId } );
				show( step.counts );
				while ( ! step.done ) {
					try {
						step = await call( 'rollback_step', { run_id: runId } );
					} catch ( error ) {
						if ( 'importright_locked' === error.code ) {
							await sleep( 2000 );
							continue;
						}
						throw error;
					}
					show( step.counts );
				}
			} catch ( error ) {
				fail( error.message );
				return;
			}
			await report( step );
		}

		async function report( step ) {
			const partial = 'partial' === step.run.rollback.status;
			const nodes = [
				el( 'h3', { text: partial ? __( 'Undo finished, with items left as they were', 'total-import' ) : __( 'Undo finished', 'total-import' ) } ),
				countList( step.counts, OUTCOMES ),
			];
			for ( const outcome of [ 'skipped', 'failed', 'kept' ] ) {
				if ( ! step.counts[ outcome ] ) {
					continue;
				}
				try {
					const found = await call( 'rollback_report', { run_id: runId, status: outcome, per_page: 50 } );
					nodes.push( el( 'h4', { text: OUTCOMES[ outcome ] } ), itemTable( found.items, OUTCOMES, OUTCOMES[ outcome ] ) );
				} catch ( error ) {
					break;
				}
			}
			nodes.push( el( 'p', {}, [ el( 'a', { href: window.location.href, className: 'button', text: __( 'Reload this run', 'total-import' ) } ) ] ) );
			box.replaceChildren( ...nodes );
			box.focus();
		}

		if ( previewButton ) {
			previewButton.addEventListener( 'click', preview );
		}

		const takeOver = $( '[data-take-over]', root );
		if ( takeOver ) {
			takeOver.addEventListener( 'click', async () => {
				const status = $( '[data-take-over-status]', root );
				takeOver.disabled = true;
				try {
					await call( 'run_unlock', { run_id: runId } );
					await call( 'run_background', { run_id: runId } );
					status.textContent = __( 'Queued in the background. Reload this page to follow it.', 'total-import' );
				} catch ( error ) {
					status.textContent = error.message;
					takeOver.disabled = false;
				}
			} );
		}
	}

	/* Migrate from WP All Import. */

	function migrate() {
		const STATUS = {
			mapped: __( 'Mapped', 'total-import' ),
			approximated: __( 'Approximated', 'total-import' ),
			unsupported: __( 'Unsupported', 'total-import' ),
		};
		const STATUS_CLASS = { mapped: 'importright-action-create', approximated: 'importright-action-update', unsupported: 'importright-action-fail' };

		function show( value ) {
			if ( null === value || undefined === value || '' === value ) {
				return '—';
			}
			return clip( 'string' === typeof value ? value : JSON.stringify( value ), 300 );
		}

		function reportTable( entries, caption ) {
			return el( 'div', { className: 'importright-scroll', tabindex: '0', role: 'region', 'aria-label': caption }, [
				el( 'table', { className: 'widefat striped importright-report importright-stack' }, [
					el( 'thead', {}, [
						el( 'tr', {}, [
							el( 'th', { scope: 'col', text: __( 'Status', 'total-import' ) } ),
							el( 'th', { scope: 'col', text: __( 'WP All Import setting', 'total-import' ) } ),
							el( 'th', { scope: 'col', text: __( 'In Total Import', 'total-import' ) } ),
							el( 'th', { scope: 'col', text: __( 'Note', 'total-import' ) } ),
						] ),
					] ),
					el(
						'tbody',
						{},
						entries.map( ( entry ) =>
							el( 'tr', {}, [
								el( 'td', { 'data-label': __( 'Status', 'total-import' ) }, [ el( 'span', { className: 'importright-status ' + ( STATUS_CLASS[ entry.status ] || '' ), text: STATUS[ entry.status ] || entry.status } ) ] ),
								el( 'td', { 'data-label': __( 'WP All Import setting', 'total-import' ) }, [ el( 'span', { className: 'importright-field-name', text: entry.wpai_key } ), el( 'br' ), el( 'span', { className: 'importright-value', text: show( entry.wpai_value ) } ) ] ),
								el( 'td', { 'data-label': __( 'In Total Import', 'total-import' ) }, [
									entry.field ? el( 'span', { className: 'importright-field-name', text: entry.field } ) : el( 'span', { className: 'importright-muted', text: __( 'Left out', 'total-import' ) } ),
									entry.field ? el( 'br' ) : null,
									entry.field ? el( 'span', { className: 'importright-value', text: show( entry.value ) } ) : null,
								] ),
								el( 'td', { 'data-label': __( 'Note', 'total-import' ), text: entry.note || '' } ),
							] )
						)
					),
				] ),
			] );
		}

		function render( box, result, dry, fromSite ) {
			const counts = result.report.counts;
			const nodes = [];
			if ( ! result.convertible ) {
				nodes.push(
					el( 'h3', { text: __( 'This import can\'t be migrated', 'total-import' ) } ),
					el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: __( 'Total Import can\'t import this kind of content. The report below says why.', 'total-import' ) } ) ] )
				);
			} else if ( dry ) {
				/* translators: %s: import name */
				nodes.push( el( 'h3', { text: sprintf( __( 'Migration preview: %s', 'total-import' ), result.name ) } ), el( 'p', { text: __( 'Nothing has been saved yet.', 'total-import' ) } ) );
			} else {
				/* translators: %s: import name */
				nodes.push( el( 'h3', { text: sprintf( __( 'Migrated: %s', 'total-import' ), result.name ) } ) );
			}

			const tracking = result.tracking;
			let explain = '';
			if ( result.convertible && fromSite && tracking ) {
				if ( tracking.skipped_reason ) {
					explain = tracking.skipped_reason + ' ' + __( 'The first run finds existing posts only through "Match content this import didn\'t create" in the import\'s matching step; without it, it creates new ones.', 'total-import' );
				} else if ( dry ) {
					explain = sprintf(
						/* translators: %s: number of records */
						__( 'WP All Import\'s record list (%s records) will be copied, so the first run updates the posts WP All Import made instead of creating copies.', 'total-import' ),
						number( tracking.available )
					);
				} else {
					explain = sprintf(
						/* translators: 1: records copied, 2: records in WP All Import's list */
						__( 'WP All Import\'s record list was copied (%1$s of %2$s records), so the first run updates the posts WP All Import made instead of creating copies. That first run updates every one of them once; later runs skip unchanged records.', 'total-import' ),
						number( tracking.copied ),
						number( tracking.available )
					);
				}
			} else if ( result.convertible && ! fromSite ) {
				explain = __( 'A template carries no record list, so the first run creates new posts unless the import\'s matching step finds existing ones (for example by SKU, slug or a custom field).', 'total-import' );
			}
			if ( ! dry && result.import_id ) {
				nodes.push(
					el( 'div', { className: 'notice notice-success inline' }, [ el( 'p', { text: __( 'The import is saved. Check its mapping, then start with a dry run.', 'total-import' ) } ) ] ),
					el( 'p', { className: 'importright-run-links' }, [
						el( 'a', { className: 'button button-primary', href: result.edit_url, text: __( 'Open the new import', 'total-import' ) } ),
						el( 'a', { className: 'button', href: result.dry_run_url, text: __( 'Dry run it now', 'total-import' ) } ),
					] )
				);
			}
			if ( explain ) {
				nodes.push( el( 'p', { text: explain } ) );
			}

			nodes.push(
				el( 'dl', { className: 'importright-counts-list' }, [
					el( 'div', { className: 'importright-count' }, [ el( 'dt', { text: STATUS.mapped } ), el( 'dd', { text: number( counts.mapped ) } ) ] ),
					el( 'div', { className: 'importright-count' }, [ el( 'dt', { text: STATUS.approximated } ), el( 'dd', { text: number( counts.approximated ) } ) ] ),
					el( 'div', { className: 'importright-count importright-count-failed' }, [ el( 'dt', { text: STATUS.unsupported } ), el( 'dd', { text: number( counts.unsupported ) } ) ] ),
				] )
			);
			const check = result.report.entries.filter( ( entry ) => 'mapped' !== entry.status );
			const mapped = result.report.entries.filter( ( entry ) => 'mapped' === entry.status );
			if ( check.length ) {
				nodes.push( el( 'h4', { text: __( 'Check these', 'total-import' ) } ), reportTable( check, __( 'Approximated and unsupported settings', 'total-import' ) ) );
			}
			if ( mapped.length ) {
				nodes.push(
					el( 'details', { className: 'importright-group' }, [
						el( 'summary', {}, [
							el( 'span', {
								className: 'importright-group-title',
								/* translators: %s: number of settings */
								text: sprintf( __( 'Mapped settings (%s)', 'total-import' ), number( mapped.length ) ),
							} ),
						] ),
						el( 'div', { className: 'importright-group-body' }, [ reportTable( mapped, __( 'Mapped settings', 'total-import' ) ) ] ),
					] )
				);
			}
			box.replaceChildren( ...nodes );
			box.hidden = false;
			box.focus();
		}

		function failed( box, message ) {
			box.replaceChildren( el( 'div', { className: 'notice notice-error inline' }, [ el( 'p', { text: message } ) ] ) );
			box.hidden = false;
			box.focus();
		}

		const siteBox = $( '#importright-migrate-site-result' );
		$$( '[data-migrate-site]' ).forEach( ( button ) =>
			button.addEventListener( 'click', async () => {
				const dry = '1' === button.dataset.dryRun;
				if (
					! dry &&
					button.dataset.migrated &&
					! window.confirm(
						sprintf(
							/* translators: %s: import ID */
							__( 'This import was already migrated, to import %s. A second copy would update the same posts, so two imports would fight over them. Migrate again anyway?', 'total-import' ),
							button.dataset.migrated
						)
					)
				) {
					return;
				}
				const buttons = $$( '[data-migrate-site]' );
				buttons.forEach( ( item ) => ( item.disabled = true ) );
				siteBox.hidden = false;
				siteBox.replaceChildren( el( 'p', { role: 'status', text: dry ? __( 'Reading the import…', 'total-import' ) : __( 'Migrating…', 'total-import' ) } ) );
				try {
					render( siteBox, await call( 'migrate_site', { wpai_id: button.dataset.migrateSite, dry_run: dry ? 1 : 0 } ), dry, true );
					if ( ! dry ) {
						button.dataset.migrated = '1';
					}
				} catch ( error ) {
					failed( siteBox, error.message );
				}
				buttons.forEach( ( item ) => ( item.disabled = false ) );
			} )
		);

		const templateBox = $( '#importright-migrate-template-result' );
		const templateStatus = $( '#importright-template-status' );
		let stored = null;
		$$( '#importright-template-file, #importright-template-data' ).forEach( ( input ) => input.addEventListener( 'change', () => ( stored = null ) ) );

		$$( '[data-migrate-template]' ).forEach( ( button ) =>
			button.addEventListener( 'click', async () => {
				const dry = '1' === button.dataset.dryRun;
				const template = $( '#importright-template-file' ).files[ 0 ];
				const dataFile = $( '#importright-template-data' ).files[ 0 ];
				if ( ! template ) {
					templateStatus.textContent = __( 'Choose a template or bundle first.', 'total-import' );
					$( '#importright-template-file' ).focus();
					return;
				}
				const buttons = $$( '[data-migrate-template]' );
				buttons.forEach( ( item ) => ( item.disabled = true ) );
				templateStatus.textContent = __( 'Uploading…', 'total-import' );
				try {
					if ( ! stored ) {
						const fields = { template };
						if ( dataFile ) {
							fields.data = dataFile;
						}
						stored = await call( 'migrate_upload', fields );
					}
					templateStatus.textContent = dry ? __( 'Reading the template…', 'total-import' ) : __( 'Migrating…', 'total-import' );
					const result = await call( 'migrate_template', {
						template: stored.template.file,
						data: stored.data ? stored.data.file : '',
						index: $( '#importright-template-index' ).value || 0,
						dry_run: dry ? 1 : 0,
					} );
					if ( ! dry && result.import_id ) {
						stored = null;
					}
					templateStatus.textContent = '';
					render( templateBox, result, dry, false );
				} catch ( error ) {
					templateStatus.textContent = '';
					failed( templateBox, error.message );
				}
				buttons.forEach( ( item ) => ( item.disabled = false ) );
			} )
		);
	}
} )();
