( function () {
	'use strict';

	const cfg = window.bmigAdmin || {};
	const byId = ( id ) => document.getElementById( id );
	const str = ( key, fallback ) => ( cfg.strings && cfg.strings[ key ] ? cfg.strings[ key ] : fallback );

	const state = {
		source: null,
		blogsRootRel: '',
		running: false,
		resumed: false,
		startLabel: '',
	};

	function postForm( formData ) {
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( ( res ) => {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} ).then( ( json ) => {
			if ( ! json || ! json.success ) {
				const message = json && json.data && json.data.message ? json.data.message : str( 'requestFailed', 'Request failed.' );
				throw new Error( message );
			}
			return json.data;
		} );
	}

	function ajax( action, data ) {
		const fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( ( key ) => fd.append( key, data[ key ] ) );
		return postForm( fd );
	}

	function status( message, isError ) {
		const el = byId( 'bmig-status' );
		if ( ! el ) {
			return;
		}
		el.textContent = message;
		el.hidden = false;
		el.classList.toggle( 'bmig-error', !! isError );
	}

	function clearStatus() {
		const el = byId( 'bmig-status' );
		if ( ! el ) {
			return;
		}
		el.textContent = '';
		el.hidden = true;
		el.classList.remove( 'bmig-error' );
	}

	function setProgress( percent ) {
		const bar = byId( 'bmig-progress-bar' );
		if ( bar ) {
			bar.style.width = percent + '%';
		}
		const label = byId( 'bmig-progress-label' );
		if ( label ) {
			label.textContent = percent + '%';
		}
	}

	function showStep( n ) {
		document.querySelectorAll( '.bmig-step' ).forEach( ( el ) => {
			if ( 'bmig-report' === el.id ) {
				return;
			}
			el.hidden = parseInt( el.id.replace( 'bmig-step-', '' ), 10 ) > n;
		} );
		document.querySelectorAll( '.bmig-tab' ).forEach( ( el ) => {
			el.classList.toggle( 'bmig-active', parseInt( el.dataset.step, 10 ) <= n );
		} );
	}

	function populateBlogs( blogs ) {
		const select = byId( 'bmig-blog' );
		select.innerHTML = '';
		blogs.forEach( function ( blog ) {
			const option = document.createElement( 'option' );
			option.value = blog.name;
			option.textContent = blog.name;
			select.appendChild( option );
		} );
	}

	function formatDuration( seconds ) {
		const mins = Math.floor( seconds / 60 );
		const secs = seconds % 60;
		return mins > 0 ? mins + 'm ' + secs + 's' : secs + 's';
	}

	function renderUrlList( listId, emptyId, urls ) {
		const list = byId( listId );
		list.innerHTML = '';
		const keys = Object.keys( urls );
		byId( emptyId ).hidden = keys.length > 0;
		keys.forEach( function ( url ) {
			const li = document.createElement( 'li' );
			li.textContent = url + ' : ' + urls[ url ];
			list.appendChild( li );
		} );
	}

	function renderReport( report ) {
		if ( ! report ) {
			return;
		}
		byId( 'bmig-r-posts' ).textContent = report.posts;
		byId( 'bmig-r-pages' ).textContent = report.pages;
		byId( 'bmig-r-comments' ).textContent = report.comments;
		byId( 'bmig-r-attachments' ).textContent = report.attachments;
		byId( 'bmig-r-matched' ).textContent = report.images_matched;
		byId( 'bmig-r-album' ).textContent = report.images_album || 0;
		byId( 'bmig-r-external' ).textContent = report.images_external || 0;
		byId( 'bmig-r-failed-count' ).textContent = Object.keys( report.images_failed || {} ).length;
		byId( 'bmig-r-posts-updated' ).textContent = report.posts_updated;
		byId( 'bmig-r-mode' ).textContent = 'Mode ' + String( report.mode ).toUpperCase();
		byId( 'bmig-r-duration' ).textContent = formatDuration( report.duration );

		const importedTotal = ( report.posts || 0 ) + ( report.pages || 0 ) + ( report.comments || 0 );
		byId( 'bmig-report-empty' ).hidden = importedTotal > 0;

		renderUrlList( 'bmig-r-unmatched', 'bmig-r-unmatched-empty', report.images_unmatched || {} );
		renderUrlList( 'bmig-r-failed', 'bmig-r-failed-empty', report.images_failed || {} );

		const conflicts = report.slug_conflicts || [];
		byId( 'bmig-report-conflicts' ).hidden = conflicts.length === 0;
		const conflictList = byId( 'bmig-r-conflicts' );
		conflictList.innerHTML = '';
		conflicts.forEach( function ( slug ) {
			const li = document.createElement( 'li' );
			li.textContent = '/' + slug + '/';
			conflictList.appendChild( li );
		} );

		byId( 'bmig-report-warning' ).hidden = 'a' !== report.mode;
		byId( 'bmig-report' ).hidden = false;
		byId( 'bmig-report' ).scrollIntoView( { behavior: 'smooth' } );
	}

	function phaseLabel( phase ) {
		switch ( phase ) {
			case 'content':
				return str( 'phaseContent', 'Importing content...' );
			case 'media':
				return str( 'phaseMedia', 'Importing images...' );
			case 'media_replace':
				return str( 'phaseReplace', 'Preparing content...' );
			case 'redirect':
				return str( 'phaseRedirect', 'Preparing redirects...' );
			default:
				return '';
		}
	}

	function openModal( message, withCancel ) {
		return new Promise( function ( resolve ) {
			const overlay = document.createElement( 'div' );
			overlay.className = 'bmig-modal-overlay';

			const dialog = document.createElement( 'div' );
			dialog.className = 'bmig-modal';
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );

			const text = document.createElement( 'p' );
			text.className = 'bmig-modal-message';
			text.textContent = message;

			const actions = document.createElement( 'div' );
			actions.className = 'bmig-modal-actions';

			const okBtn = document.createElement( 'button' );
			okBtn.type = 'button';
			okBtn.className = 'button button-primary';
			okBtn.textContent = withCancel ? str( 'confirmProceed', 'Continue' ) : str( 'confirmOk', 'OK' );

			function close( result ) {
				document.body.removeChild( overlay );
				resolve( result );
			}

			okBtn.addEventListener( 'click', function () {
				close( true );
			} );

			if ( withCancel ) {
				const cancelBtn = document.createElement( 'button' );
				cancelBtn.type = 'button';
				cancelBtn.className = 'button';
				cancelBtn.textContent = str( 'confirmCancel', 'Cancel' );
				cancelBtn.addEventListener( 'click', function () {
					close( false );
				} );
				actions.appendChild( cancelBtn );
			}

			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) {
					close( false );
				}
			} );
			document.addEventListener( 'keydown', function escHandler( e ) {
				if ( 'Escape' === e.key ) {
					document.removeEventListener( 'keydown', escHandler );
					close( false );
				}
			} );

			actions.appendChild( okBtn );
			dialog.appendChild( text );
			dialog.appendChild( actions );
			overlay.appendChild( dialog );
			document.body.appendChild( overlay );
			okBtn.focus();
		} );
	}

	async function runLoop() {
		if ( state.running ) {
			return;
		}
		state.running = true;
		byId( 'bmig-start-btn' ).disabled = true;

		let retries = 0;
		for ( ;; ) {
			try {
				const r = await ajax( 'bmig_step', { step_action: 'step' } );
				retries = 0;
				if ( r.phase ) {
					status( phaseLabel( r.phase ) );
				}
				setProgress( r.percent );
				if ( r.done ) {
					status( str( 'jobFinished', 'Migration finished.' ) );
					renderReport( r.report );
					break;
				}
			} catch ( err ) {
				retries += 1;
				if ( retries > 5 ) {
					status( str( 'batchFailed', 'Batch failed 5 times in a row.' ) + ' ' + err.message, true );
					break;
				}
				status( str( 'batchRetry', 'Batch error, retrying' ) + ' ' + retries + '/5: ' + err.message, true );
				await new Promise( function ( resolve ) {
					setTimeout( resolve, 2000 );
				} );
			}
		}

		state.running = false;
		byId( 'bmig-start-btn' ).disabled = false;
	}

	function setUploadProgress( percent ) {
		const wrap = byId( 'bmig-upload-progress' );
		const bar = byId( 'bmig-upload-progress-bar' );
		const label = byId( 'bmig-upload-progress-label' );
		if ( ! wrap ) {
			return;
		}
		if ( null === percent ) {
			wrap.hidden = true;
			if ( bar ) {
				bar.style.width = '0%';
			}
			if ( label ) {
				label.hidden = true;
				label.textContent = '0%';
			}
			return;
		}
		wrap.hidden = false;
		if ( bar ) {
			bar.style.width = percent + '%';
		}
		if ( label ) {
			label.hidden = false;
			label.textContent = percent + '%';
		}
	}

	function uploadChunk( zip, uploadId, chunkSize, index ) {
		const start = index * chunkSize;
		const end = Math.min( start + chunkSize, zip.size );
		const fd = new FormData();
		fd.append( 'action', 'bmig_chunk_upload' );
		fd.append( 'nonce', cfg.nonce );
		fd.append( 'upload_id', uploadId );
		fd.append( 'index', index );
		fd.append( 'chunk', zip.slice( start, end ), zip.name );
		return postForm( fd ).then( function () {
			return end - start;
		} );
	}

	async function uploadChunkRetry( zip, uploadId, chunkSize, index ) {
		let attempt = 0;
		for ( ;; ) {
			try {
				return await uploadChunk( zip, uploadId, chunkSize, index );
			} catch ( err ) {
				attempt += 1;
				if ( attempt >= 5 ) {
					throw new Error( str( 'chunkUploadFailed', 'Failed to upload file chunk' ) + ' ' + ( index + 1 ) + ': ' + err.message );
				}
				await new Promise( function ( resolve ) {
					setTimeout( resolve, 1000 * attempt );
				} );
			}
		}
	}

	async function uploadInChunks( zip ) {
		status( str( 'chunkUploading', 'Uploading...' ) );
		setUploadProgress( 0 );

		let init;
		try {
			init = await ajax( 'bmig_chunk_init', { filename: zip.name, size: zip.size } );
		} catch ( err ) {
			throw new Error( str( 'chunkInitFailed', 'Failed to start chunk upload' ) + ': ' + err.message );
		}

		const chunkSize = init.chunk_size;
		const totalChunks = init.total_chunks;
		let next = 0;
		let uploadedBytes = 0;

		async function worker() {
			while ( next < totalChunks ) {
				const index = next;
				next += 1;
				uploadedBytes += await uploadChunkRetry( zip, init.upload_id, chunkSize, index );
				setUploadProgress( Math.round( ( uploadedBytes / zip.size ) * 100 ) );
			}
		}

		const workers = [];
		const concurrency = Math.min( 2, totalChunks );
		for ( let w = 0; w < concurrency; w++ ) {
			workers.push( worker() );
		}
		await Promise.all( workers );

		try {
			return await ajax( 'bmig_chunk_finish', { upload_id: init.upload_id } );
		} catch ( err ) {
			throw new Error( str( 'chunkFinishFailed', 'Failed to finish upload' ) + ': ' + err.message );
		}
	}

	function initUpload() {
		const form = byId( 'bmig-upload-form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', async function ( e ) {
			e.preventDefault();
			const button = byId( 'bmig-upload-btn' );
			const spinner = byId( 'bmig-upload-spinner' );
			const fileInput = byId( 'bmig-zip' );
			const pathInput = byId( 'bmig-takeout-path' );

			const usePath = pathInput && pathInput.value.trim();
			let zip = null;
			if ( ! usePath ) {
				if ( ! fileInput.files.length ) {
					status( str( 'pickZip', 'Select a Takeout archive file first.' ), true );
					return;
				}
				zip = fileInput.files[ 0 ];
				const ext = zip.name.toLowerCase().split( '.' ).pop();
				if ( [ 'zip', 'tgz', 'gz' ].indexOf( ext ) === -1 ) {
					status( str( 'invalidType', 'File must be a zip or tgz archive.' ), true );
					return;
				}
				if ( zip.size > cfg.maxZipMb * 1024 * 1024 ) {
					status( str( 'zipTooLarge', 'Archive exceeds the plugin limit.' ) + ' (' + cfg.maxZipMb + ' MB)', true );
					return;
				}
			}

			button.disabled = true;
			spinner.classList.add( 'is-active' );
			try {
				let data;
				if ( usePath ) {
					const fd = new FormData();
					fd.append( 'action', 'bmig_upload' );
					fd.append( 'nonce', cfg.nonce );
					fd.append( 'takeout_path', pathInput.value.trim() );
					data = await postForm( fd );
				} else {
					data = await uploadInChunks( zip );
				}
				state.source = data.source;
				state.blogsRootRel = data.blogs_root_rel;
				state.resumed = false;
				if ( state.startLabel ) {
					byId( 'bmig-start-btn' ).textContent = state.startLabel;
				}
				populateBlogs( data.blogs );
				showStep( 2 );
				clearStatus();
			} catch ( err ) {
				status( str( 'uploadFailed', 'Upload failed' ) + ': ' + err.message, true );
			}
			button.disabled = false;
			spinner.classList.remove( 'is-active' );
			setUploadProgress( null );
		} );
	}

	async function loadSummary() {
		const summary = byId( 'bmig-summary' );
		if ( ! summary || ! state.source ) {
			return;
		}
		summary.hidden = false;
		byId( 'bmig-sum-blog' ).textContent = byId( 'bmig-blog' ).value;
		[ 'posts', 'pages', 'comments', 'images' ].forEach( function ( key ) {
			byId( 'bmig-sum-' + key ).textContent = '...';
		} );
		try {
			const data = await ajax( 'bmig_summary', {
				blog: byId( 'bmig-blog' ).value,
				source_type: state.source.type,
				source_rel: state.source.rel || '',
				source_path: state.source.path || '',
				blogs_rel: state.blogsRootRel,
			} );
			byId( 'bmig-sum-posts' ).textContent = data.posts;
			byId( 'bmig-sum-pages' ).textContent = data.pages;
			byId( 'bmig-sum-comments' ).textContent = data.comments;
			byId( 'bmig-sum-images' ).textContent = data.images;
		} catch ( err ) {
			status( str( 'summaryFailed', 'Failed to load blog summary.' ) + ': ' + err.message, true );
		}
	}

	function initConfig() {
		byId( 'bmig-config-btn' ).addEventListener( 'click', function () {
			showStep( 3 );
			loadSummary();
		} );
	}

	function initStart() {
		byId( 'bmig-start-btn' ).addEventListener( 'click', async function () {
			// Resume: job tersimpan dilanjutkan dari phase/cursor terakhir,
			// tanpa membuat job baru.
			if ( state.resumed && cfg.job && ! cfg.job.done ) {
				byId( 'bmig-progress' ).hidden = false;
				byId( 'bmig-progress-label' ).hidden = false;
				runLoop();
				return;
			}
			if ( ! state.source ) {
				status( str( 'noSource', 'No Takeout source selected, restart from step 1.' ), true );
				return;
			}
			if ( cfg.job && ! cfg.job.done && ! ( await openModal( str( 'confirmPending', 'A previous job is still unfinished. Start over?' ), true ) ) ) {
				return;
			}
			const modeInput = document.querySelector( 'input[name="bmig_mode"]:checked' );
			const mode = modeInput ? modeInput.value : 'a';
			if ( ! ( await openModal( str( 'confirmMode', 'This mode changes the site permalink structure. Continue?' ), true ) ) ) {
				return;
			}
			const mediaAlbum = byId( 'bmig-media-album' );
			const mediaExternal = byId( 'bmig-media-external' );
			byId( 'bmig-progress' ).hidden = false;
			byId( 'bmig-progress-label' ).hidden = false;
			try {
				const r = await ajax( 'bmig_step', {
					step_action: 'start',
					blog: byId( 'bmig-blog' ).value,
					mode: mode,
					media_album: mediaAlbum && mediaAlbum.checked ? 1 : 0,
					media_external: mediaExternal && mediaExternal.checked ? 1 : 0,
					to_blocks: byId( 'bmig-to-blocks' ).checked ? 1 : 0,
					source_type: state.source.type,
					source_rel: state.source.rel || '',
					source_path: state.source.path || '',
					blogs_rel: state.blogsRootRel,
				} );
				if ( r.phase ) {
					status( phaseLabel( r.phase ) );
				}
				setProgress( r.percent );
				runLoop();
			} catch ( err ) {
				status( str( 'startFailed', 'Failed to start job' ) + ': ' + err.message, true );
			}
		} );
	}

	function initReset() {
		byId( 'bmig-reset-btn' ).addEventListener( 'click', async function () {
			if ( ! ( await openModal( str( 'confirmReset', 'The job state will be cleared. Continue?' ), true ) ) ) {
				return;
			}
			try {
				await ajax( 'bmig_step', { step_action: 'reset' } );
				window.location.reload();
			} catch ( err ) {
				status( str( 'resetFailed', 'Failed to reset job' ) + ': ' + err.message, true );
			}
		} );
	}

	// Saat halaman dimuat dengan job yang belum selesai, hydrasi state dari
	// job tersimpan dan tawarkan langkah 3 untuk melanjutkan job.
	function resumeFromJob() {
		if ( ! cfg.job || cfg.job.done ) {
			return;
		}
		if ( cfg.job.source && cfg.job.source.type ) {
			state.source = cfg.job.source;
			state.blogsRootRel = cfg.job.blogs_rel || '';
		}
		if ( cfg.job.blog ) {
			populateBlogs( [ { name: cfg.job.blog } ] );
		}
		state.resumed = true;
		const startBtn = byId( 'bmig-start-btn' );
		state.startLabel = startBtn.textContent;
		startBtn.textContent = str( 'resumeJob', 'Resume job' );
		byId( 'bmig-progress' ).hidden = false;
		byId( 'bmig-progress-label' ).hidden = false;
		setProgress( cfg.job.percent || 0 );
		status( str( 'resumedTo', 'Resuming job from phase:' ) + ' ' + phaseLabel( cfg.job.phase ) );
		showStep( 3 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initUpload();
		initConfig();
		initStart();
		initReset();
		resumeFromJob();
	} );
}() );
