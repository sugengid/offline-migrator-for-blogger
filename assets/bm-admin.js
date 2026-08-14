( function () {
	'use strict';

	const cfg = window.bmAdmin || {};
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
				const message = json && json.data && json.data.message ? json.data.message : str( 'requestFailed', 'Request gagal.' );
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
		const el = byId( 'bm-status' );
		if ( ! el ) {
			return;
		}
		el.textContent = message;
		el.hidden = false;
		el.classList.toggle( 'bm-error', !! isError );
	}

	function setProgress( percent ) {
		const bar = byId( 'bm-progress-bar' );
		if ( bar ) {
			bar.style.width = percent + '%';
		}
		const label = byId( 'bm-progress-label' );
		if ( label ) {
			label.textContent = percent + '%';
		}
	}

	function showStep( n ) {
		document.querySelectorAll( '.bm-step' ).forEach( ( el ) => {
			if ( 'bm-report' === el.id ) {
				return;
			}
			el.hidden = parseInt( el.id.replace( 'bm-step-', '' ), 10 ) > n;
		} );
		document.querySelectorAll( '.bm-tab' ).forEach( ( el ) => {
			el.classList.toggle( 'bm-active', parseInt( el.dataset.step, 10 ) <= n );
		} );
	}

	function populateBlogs( blogs ) {
		const select = byId( 'bm-blog' );
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
		byId( 'bm-r-posts' ).textContent = report.posts;
		byId( 'bm-r-pages' ).textContent = report.pages;
		byId( 'bm-r-comments' ).textContent = report.comments;
		byId( 'bm-r-attachments' ).textContent = report.attachments;
		byId( 'bm-r-matched' ).textContent = report.images_matched;
		byId( 'bm-r-album' ).textContent = report.images_album || 0;
		byId( 'bm-r-external' ).textContent = report.images_external || 0;
		byId( 'bm-r-failed-count' ).textContent = Object.keys( report.images_failed || {} ).length;
		byId( 'bm-r-posts-updated' ).textContent = report.posts_updated;
		byId( 'bm-r-mode' ).textContent = 'Mode ' + String( report.mode ).toUpperCase();
		byId( 'bm-r-duration' ).textContent = formatDuration( report.duration );

		const importedTotal = ( report.posts || 0 ) + ( report.pages || 0 ) + ( report.comments || 0 );
		byId( 'bm-report-empty' ).hidden = importedTotal > 0;

		renderUrlList( 'bm-r-unmatched', 'bm-r-unmatched-empty', report.images_unmatched || {} );
		renderUrlList( 'bm-r-failed', 'bm-r-failed-empty', report.images_failed || {} );

		const conflicts = report.slug_conflicts || [];
		byId( 'bm-report-conflicts' ).hidden = conflicts.length === 0;
		const conflictList = byId( 'bm-r-conflicts' );
		conflictList.innerHTML = '';
		conflicts.forEach( function ( slug ) {
			const li = document.createElement( 'li' );
			li.textContent = '/' + slug + '/';
			conflictList.appendChild( li );
		} );

		byId( 'bm-report-warning' ).hidden = 'a' !== report.mode;
		byId( 'bm-report' ).hidden = false;
		byId( 'bm-report' ).scrollIntoView( { behavior: 'smooth' } );
	}

	function phaseLabel( phase ) {
		switch ( phase ) {
			case 'content':
				return str( 'phaseContent', 'Mengimpor konten...' );
			case 'media':
				return str( 'phaseMedia', 'Mengimpor gambar...' );
			case 'media_replace':
				return str( 'phaseReplace', 'Menyiapkan konten...' );
			case 'redirect':
				return str( 'phaseRedirect', 'Menyiapkan redirect...' );
			default:
				return '';
		}
	}

	function openModal( message, withCancel ) {
		return new Promise( function ( resolve ) {
			const overlay = document.createElement( 'div' );
			overlay.className = 'bm-modal-overlay';

			const dialog = document.createElement( 'div' );
			dialog.className = 'bm-modal';
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );

			const text = document.createElement( 'p' );
			text.className = 'bm-modal-message';
			text.textContent = message;

			const actions = document.createElement( 'div' );
			actions.className = 'bm-modal-actions';

			const okBtn = document.createElement( 'button' );
			okBtn.type = 'button';
			okBtn.className = 'button button-primary';
			okBtn.textContent = withCancel ? str( 'confirmProceed', 'Lanjutkan' ) : str( 'confirmOk', 'OK' );

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
				cancelBtn.textContent = str( 'confirmCancel', 'Batal' );
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
		byId( 'bm-start-btn' ).disabled = true;

		let retries = 0;
		for ( ;; ) {
			try {
				const r = await ajax( 'bm_step', { step_action: 'step' } );
				retries = 0;
				if ( r.phase ) {
					status( phaseLabel( r.phase ) );
				}
				setProgress( r.percent );
				if ( r.done ) {
					status( str( 'jobFinished', 'Migrasi selesai.' ) );
					renderReport( r.report );
					window.location.reload();
					break;
				}
			} catch ( err ) {
				retries += 1;
				if ( retries > 5 ) {
					status( str( 'batchFailed', 'Batch gagal 5x berturut-turut.' ) + ' ' + err.message, true );
					break;
				}
				status( str( 'batchRetry', 'Batch error, coba ulang' ) + ' ' + retries + '/5: ' + err.message, true );
				await new Promise( function ( resolve ) {
					setTimeout( resolve, 2000 );
				} );
			}
		}

		state.running = false;
		byId( 'bm-start-btn' ).disabled = false;
	}

	function initUpload() {
		const form = byId( 'bm-upload-form' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', async function ( e ) {
			e.preventDefault();
			const button = byId( 'bm-upload-btn' );
			const spinner = byId( 'bm-upload-spinner' );
			const fileInput = byId( 'bm-zip' );
			const pathInput = byId( 'bm-takeout-path' );

			const fd = new FormData();
			fd.append( 'action', 'bm_upload' );
			fd.append( 'nonce', cfg.nonce );

			if ( pathInput && pathInput.value.trim() ) {
				fd.append( 'takeout_path', pathInput.value.trim() );
			} else if ( fileInput.files.length ) {
				const zip = fileInput.files[ 0 ];
				if ( zip.size > cfg.maxZipMb * 1024 * 1024 ) {
					status( str( 'zipTooLarge', 'Arsip melebihi batas plugin.' ) + ' (' + cfg.maxZipMb + ' MB)', true );
					return;
				}
				if ( cfg.phpLimitMb > 0 && zip.size > cfg.phpLimitMb * 1024 * 1024 ) {
					status( str( 'zipOverPhpLimit', 'Ukuran arsip melebihi batas upload PHP server.' ) + ' (' + cfg.phpLimitMb + ' MB)', true );
					return;
				}
				fd.append( 'bm_zip', zip );
			} else {
				status( str( 'pickZip', 'Pilih file arsip Takeout dulu.' ), true );
				return;
			}

			button.disabled = true;
			spinner.classList.add( 'is-active' );
			try {
				const data = await postForm( fd );
				state.source = data.source;
				state.blogsRootRel = data.blogs_root_rel;
				state.resumed = false;
				if ( state.startLabel ) {
					byId( 'bm-start-btn' ).textContent = state.startLabel;
				}
				populateBlogs( data.blogs );
				showStep( 2 );
			} catch ( err ) {
				status( str( 'uploadFailed', 'Upload gagal' ) + ': ' + err.message, true );
			}
			button.disabled = false;
			spinner.classList.remove( 'is-active' );
		} );
	}

	async function loadSummary() {
		const summary = byId( 'bm-summary' );
		if ( ! summary || ! state.source ) {
			return;
		}
		summary.hidden = false;
		byId( 'bm-sum-blog' ).textContent = byId( 'bm-blog' ).value;
		[ 'posts', 'pages', 'comments', 'images' ].forEach( function ( key ) {
			byId( 'bm-sum-' + key ).textContent = '...';
		} );
		try {
			const data = await ajax( 'bm_summary', {
				blog: byId( 'bm-blog' ).value,
				source_type: state.source.type,
				source_rel: state.source.rel || '',
				source_path: state.source.path || '',
				blogs_rel: state.blogsRootRel,
			} );
			byId( 'bm-sum-posts' ).textContent = data.posts;
			byId( 'bm-sum-pages' ).textContent = data.pages;
			byId( 'bm-sum-comments' ).textContent = data.comments;
			byId( 'bm-sum-images' ).textContent = data.images;
		} catch ( err ) {
			status( str( 'summaryFailed', 'Gagal memuat ringkasan blog.' ) + ': ' + err.message, true );
		}
	}

	function initConfig() {
		byId( 'bm-config-btn' ).addEventListener( 'click', function () {
			showStep( 3 );
			loadSummary();
		} );
	}

	function initStart() {
		byId( 'bm-start-btn' ).addEventListener( 'click', async function () {
			// Resume: job tersimpan dilanjutkan dari phase/cursor terakhir,
			// tanpa membuat job baru.
			if ( state.resumed && cfg.job && ! cfg.job.done ) {
				byId( 'bm-progress' ).hidden = false;
				byId( 'bm-progress-label' ).hidden = false;
				runLoop();
				return;
			}
			if ( ! state.source ) {
				status( str( 'noSource', 'Sumber Takeout belum dipilih, ulangi dari langkah 1.' ), true );
				return;
			}
			if ( cfg.job && ! cfg.job.done && ! ( await openModal( str( 'confirmPending', 'Job sebelumnya belum selesai. Mulai dari awal?' ), true ) ) ) {
				return;
			}
			const modeInput = document.querySelector( 'input[name="bm_mode"]:checked' );
			const mode = modeInput ? modeInput.value : 'a';
			if ( ! ( await openModal( str( 'confirmMode', 'Mode ini mengubah struktur permalink situs. Lanjutkan?' ), true ) ) ) {
				return;
			}
			const mediaAlbum = byId( 'bm-media-album' );
			const mediaExternal = byId( 'bm-media-external' );
			byId( 'bm-progress' ).hidden = false;
			byId( 'bm-progress-label' ).hidden = false;
			try {
				const r = await ajax( 'bm_step', {
					step_action: 'start',
					blog: byId( 'bm-blog' ).value,
					mode: mode,
					media_album: mediaAlbum && mediaAlbum.checked ? 1 : 0,
					media_external: mediaExternal && mediaExternal.checked ? 1 : 0,
					to_blocks: byId( 'bm-to-blocks' ).checked ? 1 : 0,
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
				status( str( 'startFailed', 'Gagal memulai job' ) + ': ' + err.message, true );
			}
		} );
	}

	function initReset() {
		byId( 'bm-reset-btn' ).addEventListener( 'click', async function () {
			if ( ! ( await openModal( str( 'confirmReset', 'State job akan dihapus. Lanjutkan?' ), true ) ) ) {
				return;
			}
			try {
				await ajax( 'bm_step', { step_action: 'reset' } );
				window.location.reload();
			} catch ( err ) {
				status( str( 'resetFailed', 'Gagal reset job' ) + ': ' + err.message, true );
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
		const startBtn = byId( 'bm-start-btn' );
		state.startLabel = startBtn.textContent;
		startBtn.textContent = str( 'resumeJob', 'Lanjutkan job' );
		byId( 'bm-progress' ).hidden = false;
		byId( 'bm-progress-label' ).hidden = false;
		setProgress( cfg.job.percent || 0 );
		status( str( 'resumedTo', 'Melanjutkan job dari fase:' ) + ' ' + phaseLabel( cfg.job.phase ) );
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
