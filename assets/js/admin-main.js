/*
 * Admin JavaScript for LinkSentinel.
 *
 * Handles tab navigation, kicking off scans, polling for progress and
 * updating the UI accordingly.  This script runs only on the plugin
 * dashboard page because it is enqueued conditionally in PHP.
 */
(function ( $ ) {
    'use strict';
    $( document ).ready( function () {
        // Tab navigation: show/hide content on click.
        const $tabs        = $( '.nav-tab-wrapper a.nav-tab' );
        const $tabContents = $( '.tab-content' );
        $tabs.on( 'click', function ( e ) {
            e.preventDefault();
            const target = $( this ).attr( 'href' );
            // Switch the active class to the clicked tab and toggle content.
            $tabs.removeClass( 'nav-tab-active' );
            $( this ).addClass( 'nav-tab-active' );
            $tabContents.hide();
            $( target ).show();
            /*
             * Update the activeTab variable whenever a tab is clicked, but
             * explicitly ignore the Settings tab.  The Settings tab is
             * primarily used for configuring scan schedules and does not
             * represent a state we want to return to after scans complete.
             * Without this guard, navigating to the Settings tab would
             * overwrite the previously active tab (e.g. Resolved, Pending
             * or Broken) causing the dashboard to reopen on the Settings
             * tab after an auto‑fix, which is confusing.  See #233 for
             * details.
             */
            if ( target !== '#settings' ) {
                activeTab = target || null;
            }
        } );
        // Capture the currently active tab at page load.  If a scan is already
        // in progress when the user visits or refreshes the page (for example,
        // a nightly scan running in the background), we need to know which
        // tab was selected so we can return to it when the scan completes.
        let activeTab = $( '.nav-tab-wrapper a.nav-tab-active' ).attr( 'href' ) || null;

        // Scan button handler and batch processing utilities.
        const $scanBtn = $( '#rfx-start-scan' );
        const $feedback = $( '#rfx-scan-feedback' );
        const $statusBox = $( '#rfx-scan-status' );
        const $statusText = $( '#rfx-scan-status-text' );
        const $progressBar = $( '#rfx-scan-progress' );
        const $statusSpinner = $statusBox.find( '.spinner' );
        const $statusHeading = $statusBox.find( 'strong' );
        const STEP_DELAY = 250;
        let pollTimer = null;
        let autoStatusInterval = null;
        let scanToken = null;
        let stepTimer = null;
        let stepInFlight = false;
        let stepErrorCount = 0;
        const MAX_STEP_ERRORS = 5;
        let scanActive = false;
        let awaitingReload = false;

        function setSpinnerActive( isActive ) {
            if ( isActive ) {
                $statusSpinner.addClass( 'is-active' );
                $statusSpinner.css( 'visibility', 'visible' );
            } else {
                $statusSpinner.removeClass( 'is-active' );
                $statusSpinner.css( 'visibility', 'hidden' );
            }
        }

        function updateUI( data ) {
            if ( ! data ) {
                return;
            }
            const total     = data.total_posts || data.total || 0;
            const processed = data.processed || 0;
            const running   = !! data.running;

            if ( running ) {
                $statusHeading.text( window.RFXAdmin && RFXAdmin.labels && RFXAdmin.labels.inProgress ? RFXAdmin.labels.inProgress : 'Scan in progress' );
                setSpinnerActive( true );
                $statusBox.show();
            } else if ( awaitingReload ) {
                $statusHeading.text( window.RFXAdmin && RFXAdmin.labels && RFXAdmin.labels.completed ? RFXAdmin.labels.completed : 'Scan complete' );
                setSpinnerActive( false );
                $statusBox.show();
            } else {
                setSpinnerActive( false );
                $statusBox.hide();
                $statusHeading.text( window.RFXAdmin && RFXAdmin.labels && RFXAdmin.labels.inProgress ? RFXAdmin.labels.inProgress : 'Scan in progress' );
                $statusText.text( '' );
                $progressBar.css( 'width', '0' );
                return;
            }

            const pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 0;
            let message = data.message || '';
            if ( ! message && running ) {
                message = 'Scanning…';
            } else if ( ! message && awaitingReload ) {
                message = 'Scan complete.';
            }
            $statusText.text( message ? ' ' + message : '' );
            $progressBar.css( 'width', pct + '%' );
        }

        function setScanToken( token ) {
            scanToken = token || null;
        }

        function clearStepTimer() {
            if ( stepTimer ) {
                clearTimeout( stepTimer );
                stepTimer = null;
            }
        }

        function scheduleNextStep( delay ) {
            if ( ! scanToken || stepInFlight || awaitingReload ) {
                return;
            }
            clearStepTimer();
            stepTimer = setTimeout( sendStep, typeof delay === 'number' ? delay : STEP_DELAY );
        }

        function sendStep() {
            if ( ! scanToken || stepInFlight ) {
                return;
            }
            stepInFlight = true;
            clearStepTimer();
            $.ajax( {
                url: ( window.RFXAdmin && RFXAdmin.ajax_url ) || ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'rfx_step_scan',
                    token: scanToken,
                    _ajax_nonce: ( window.RFXAdmin && RFXAdmin.nonce ) || '',
                },
            } ).done( function ( res ) {
                stepInFlight = false;
                if ( res && res.success ) {
                    stepErrorCount = 0;
                    const payload = res.data || {};
                    payload.total_posts = payload.total || payload.total_posts || 0;
                    payload.running     = ! payload.done;
                    updateUI( payload );
                    if ( payload.done ) {
                        handleScanCompletion( payload );
                    } else {
                        scheduleNextStep( STEP_DELAY );
                    }
                } else {
                    if ( res && res.data && res.data.message ) {
                        $feedback.text( res.data.message );
                    } else {
                        $feedback.text( 'Batch request failed.' );
                    }
                    handleStepFailure();
                }
            } ).fail( function ( jqXHR, textStatus, errorThrown ) {
                stepInFlight = false;
                console.error( '[LinkSentinel] sendStep AJAX Request Failed' );
                console.error( '  Status:', jqXHR.status );
                console.error( '  Status Text:', textStatus );
                console.error( '  Error Thrown:', errorThrown );
                console.error( '  Response Text:', jqXHR.responseText );
                console.error( '  Action: rfx_step_scan' );
                console.error( '  Token:', scanToken );
                $feedback.text( 'Batch request failed.' );
                handleStepFailure();
            } );
        }

        // Retry a failed batch with backoff, but stop after a few consecutive
        // failures (e.g. an invalidated token) instead of retrying forever
        // with the Scan button stuck disabled.  The 30s status poll will
        // re-attach to the scan if it is in fact still running server-side.
        function handleStepFailure() {
            stepErrorCount++;
            if ( stepErrorCount < MAX_STEP_ERRORS ) {
                scheduleNextStep( 2000 );
                return;
            }
            stepErrorCount = 0;
            scanActive     = false;
            setScanToken( null );
            clearStepTimer();
            if ( pollTimer ) {
                clearInterval( pollTimer );
                pollTimer = null;
            }
            setSpinnerActive( false );
            $feedback.text( 'Scan stopped after repeated errors. Please reload the page and try again.' );
            $scanBtn.prop( 'disabled', false );
        }

        function handleScanCompletion( payload ) {
            if ( awaitingReload ) {
                return;
            }
            awaitingReload = true;
            scanActive     = false;
            setScanToken( null );
            clearStepTimer();
            if ( pollTimer ) {
                clearInterval( pollTimer );
                pollTimer = null;
            }
            setSpinnerActive( false );
            $statusHeading.text( window.RFXAdmin && RFXAdmin.labels && RFXAdmin.labels.completed ? RFXAdmin.labels.completed : 'Scan complete' );
            updateUI( $.extend( { running: false }, payload || {} ) );
            $statusBox.show();
            $statusText.text( ' Scan completed! Updating results…' );
            $progressBar.css( 'width', '100%' );
            setTimeout( function () {
                window.location.hash = activeTab || '';
                window.location.reload();
            }, 1500 );
            $scanBtn.prop( 'disabled', false );
        }

        function handleStatusData( data ) {
            if ( ! data ) {
                return;
            }
            updateUI( data );
            if ( data.running ) {
                awaitingReload = false;
                scanActive     = true;
                if ( data.token ) {
                    if ( scanToken !== data.token ) {
                        setScanToken( data.token );
                    }
                    scheduleNextStep( 0 );
                }
            } else if ( ( scanActive || scanToken ) && ! awaitingReload ) {
                handleScanCompletion( data );
            }
        }

        function pollStatusOnce( cb ) {
            $.ajax( {
                url: ( window.RFXAdmin && RFXAdmin.ajax_url ) || ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'rfx_scan_status',
                    _ajax_nonce: ( window.RFXAdmin && RFXAdmin.nonce ) || '',
                },
            } ).done( function ( res ) {
                if ( res && res.success ) {
                    handleStatusData( res.data );
                    if ( typeof cb === 'function' ) {
                        cb( res.data );
                    }
                }
            } ).fail( function ( jqXHR, textStatus, errorThrown ) {
                console.error( '[LinkSentinel] pollStatusOnce AJAX Request Failed' );
                console.error( '  Status:', jqXHR.status );
                console.error( '  Status Text:', textStatus );
                console.error( '  Error Thrown:', errorThrown );
                console.error( '  Response Text:', jqXHR.responseText );
                console.error( '  Action: rfx_scan_status' );
            } );
        }

        /*
         * Begin polling scan status on a fixed interval.  Used while a scan
         * is running so the UI keeps updating even when this tab is not the
         * one driving the step requests.  Guarded so only one poll timer
         * runs at a time.
         */
        function startPolling() {
            if ( pollTimer ) {
                return;
            }
            pollTimer = setInterval( function () {
                pollStatusOnce();
            }, 3000 );
        }

        // Guard to prevent duplicate start_scan requests from rapid clicks.
        let scanStarting = false;

        // Start scan click.
        $scanBtn.on( 'click', function () {
            // Immediately disable and guard to prevent double-click races.
            if ( scanStarting || scanActive ) {
                return;
            }
            scanStarting = true;
            activeTab      = $( '.nav-tab-wrapper a.nav-tab-active' ).attr( 'href' ) || null;
            awaitingReload = false;
            $scanBtn.prop( 'disabled', true );
            $feedback.text( 'Starting...' ).show();
            $statusBox.show();
            $.ajax( {
                url: ( window.RFXAdmin && RFXAdmin.ajax_url ) || ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'rfx_start_scan',
                    _ajax_nonce: ( window.RFXAdmin && RFXAdmin.nonce ) || '',
                },
            } ).done( function ( res ) {
                if ( res && res.success ) {
                    const payload = res.data || {};
                    $feedback.text( payload.message || 'Scan queued.' );
                    if ( payload.token ) {
                        setScanToken( payload.token );
                        scanActive = true;
                        scanStarting = false;
                        startPolling();
                        scheduleNextStep( 0 );
                    } else {
                        scanStarting = false;
                        $scanBtn.prop( 'disabled', false );
                    }
                } else if ( res && res.data && res.data.message ) {
                    $feedback.text( res.data.message );
                    scanStarting = false;
                    $scanBtn.prop( 'disabled', false );
                } else {
                    $feedback.text( 'Unexpected response.' );
                    scanStarting = false;
                    $scanBtn.prop( 'disabled', false );
                }
            } ).fail( function ( jqXHR, textStatus, errorThrown ) {
                console.error( '[LinkSentinel] Start Scan AJAX Request Failed' );
                console.error( '  Status:', jqXHR.status );
                console.error( '  Status Text:', textStatus );
                console.error( '  Error Thrown:', errorThrown );
                console.error( '  Response Text:', jqXHR.responseText );
                console.error( '  Action: rfx_start_scan' );
                $feedback.text( 'Server error.' );
                scanStarting = false;
                $scanBtn.prop( 'disabled', false );
            } );
        } );

        // On page load, check if a scan is in progress and start polling.
        pollStatusOnce( function ( data ) {
            if ( data && data.running ) {
                scanActive = true;
                startPolling();
                scheduleNextStep( 0 );
            }
        } );

        // Automatically poll scan status periodically.
        autoStatusInterval = setInterval( function () {
            if ( pollTimer ) {
                return;
            }
            pollStatusOnce( function ( data ) {
                if ( data && data.running ) {
                    scanActive = true;
                    startPolling();
                    scheduleNextStep( 0 );
                }
            } );
        }, 30000 );

        /*
         * If the URL contains a hash (e.g. #broken or #pending) when the page
         * loads, activate the corresponding tab automatically.  This
         * supports returning to the same tab after an action that reloads
         * the page, such as changing a broken link.  We trigger a click
         * event on the matching tab to reuse the existing navigation
         * handler.
         */
        ( function () {
            // If the page loaded with an active tab hash, show that tab immediately.
            const hash = window.location.hash;
            if ( hash ) {
                const $target = $( '.nav-tab-wrapper a[href="' + hash + '"]' );
                if ( $target.length ) {
                    // Defer the trigger to ensure the DOM is fully initialized.
                    setTimeout( function () {
                        $target.trigger( 'click' );
                    }, 0 );
                }
            }
        } )();

        // Diagnostic function to test database connection
        window.rfxTestDbConnection = function() {
            console.log('[LinkSentinel] Testing database connection...');
            const nonce = ( window.RFXAdmin && RFXAdmin.nonce ) || '';

            if (!nonce) {
                console.error('[LinkSentinel] No nonce found. Make sure you are on the LinkSentinel admin page.');
                return;
            }

            $.ajax({
                url: (window.RFXAdmin && RFXAdmin.ajax_url) || ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'rfx_test_db_connection',
                    _ajax_nonce: nonce
                }
            }).done(function(res) {
                console.log('[LinkSentinel] Database diagnostics:', res);
                if (res.success && res.data && res.data.diagnostics) {
                    const diag = res.data.diagnostics;
                    console.table({
                        'Table Exists': diag.table_exists,
                        'Total Records': diag.total_records,
                        'Pending Records': diag.pending_records,
                        'Can Read': diag.can_read,
                        'Last DB Error': diag.last_error || 'None',
                        'Memory Limit': diag.memory_limit,
                        'Max Execution Time': diag.max_execution_time,
                        'Current Memory': diag.current_memory_usage,
                        'Peak Memory': diag.peak_memory_usage
                    });
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error('[LinkSentinel] Diagnostic test failed:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    errorThrown: errorThrown,
                    responseText: jqXHR.responseText
                });
            });
        };

        /*
         * Resolve all pending redirects at once.  When the user clicks the
         * "Resolve All" button above the pending table, we gather the nonce
         * from the button’s data attribute and send an AJAX request to
         * rfx_resolve_all.  On success the page reloads to reflect the
         * newly resolved items.
         */
        const resolveAllBatchSetting = parseInt( ( window.RFXAdmin && window.RFXAdmin.resolve_all_batch ), 10 );
        const resolveAllDelaySetting = parseInt( ( window.RFXAdmin && window.RFXAdmin.resolve_all_delay ), 10 );
        const RESOLVE_ALL_BATCH = ( ! isNaN( resolveAllBatchSetting ) && resolveAllBatchSetting > 0 )
            ? resolveAllBatchSetting
            : 5;
        const RESOLVE_ALL_DELAY = ( ! isNaN( resolveAllDelaySetting ) && resolveAllDelaySetting >= 0 )
            ? resolveAllDelaySetting
            : 800;
        const MIN_RESOLVE_ALL_BATCH = 1;
        const MAX_RESOLVE_ALL_DELAY = 60000;
        const MAX_RETRY_ATTEMPTS = 3;

        let resolveAllState = null;
        let resolveAllTimer = null;
        let resolveAllRetryCount = 0; // Track retry attempts

        function clampResolveAllBatch( value ) {
            const parsed = parseInt( value, 10 );
            if ( isNaN( parsed ) || parsed <= 0 ) {
                return resolveAllState && resolveAllState.batch ? resolveAllState.batch : RESOLVE_ALL_BATCH;
            }
            return Math.max( MIN_RESOLVE_ALL_BATCH, parsed );
        }

        function resetResolveAllTimer() {
            if ( resolveAllTimer ) {
                clearTimeout( resolveAllTimer );
                resolveAllTimer = null;
            }
        }

        function updateResolveAllLabel( $btn ) {
            if ( ! resolveAllState ) {
                return;
            }
            if ( resolveAllState.total > 0 && resolveAllState.processed <= resolveAllState.total ) {
                const pct = Math.min( 100, Math.round( ( resolveAllState.processed / resolveAllState.total ) * 100 ) );
                $btn.text( 'Resolving… ' + resolveAllState.processed + '/' + resolveAllState.total + ' (' + pct + '%)' );
            } else if ( resolveAllState.processed > 0 ) {
                $btn.text( 'Resolving… ' + resolveAllState.processed );
            } else {
                $btn.text( 'Resolving…' );
            }
        }

        function scheduleResolveAllStep( $btn, delay ) {
            resetResolveAllTimer();
            const fallbackDelay = RESOLVE_ALL_DELAY;
            const stateDelay = resolveAllState && typeof resolveAllState.delay === 'number'
                ? resolveAllState.delay
                : fallbackDelay;
            const effectiveDelay = typeof delay === 'number'
                ? delay
                : stateDelay;
            resolveAllTimer = setTimeout( function () {
                runResolveAllStep( $btn );
            }, Math.max( 0, effectiveDelay ) );
        }

        function applyResolveAllTimingHints( data ) {
            if ( ! resolveAllState ) {
                return;
            }

            resolveAllState.delay = RESOLVE_ALL_DELAY;

            if ( typeof data.next_batch === 'number' && data.next_batch > 0 ) {
                resolveAllState.batch = clampResolveAllBatch( data.next_batch );
                return;
            }

            if ( typeof data.last_step_seconds !== 'number' ) {
                return;
            }

            const budget = ( typeof data.step_budget === 'number' && data.step_budget > 0 )
                ? data.step_budget
                : 12;
            resolveAllState.stepBudget = budget;
            const upperThreshold = Math.max( 4, budget * 0.85 );
            const lowerThreshold = Math.max( 2, budget * 0.5 );

            const elapsed = data.last_step_seconds;
            if ( elapsed >= upperThreshold ) {
                resolveAllState.batch = clampResolveAllBatch( Math.floor( resolveAllState.batch / 2 ) || 1 );
                const bump = Math.min( MAX_RESOLVE_ALL_DELAY, RESOLVE_ALL_DELAY + 1000 );
                resolveAllState.delay = bump;
            } else if ( elapsed <= lowerThreshold ) {
                resolveAllState.batch = clampResolveAllBatch( resolveAllState.batch + 1 );
            }
        }

        function handleResolveAllError( $btn, message, isRetryable ) {
            if ( ! resolveAllState ) {
                if ( message ) {
                    alert( message );
                }
                if ( $btn && $btn.length ) {
                    $btn.prop( 'disabled', false );
                    $btn.text( resolveAllState ? resolveAllState.originalText : 'Resolve All' );
                }
                return;
            }

            // Check if we should retry
            if ( isRetryable !== false && resolveAllRetryCount < MAX_RETRY_ATTEMPTS ) {
                resolveAllRetryCount++;
                console.log( '[LinkSentinel] Retrying resolve all (attempt ' + resolveAllRetryCount + '/' + MAX_RETRY_ATTEMPTS + ')' );
                
                // Reduce batch size on retry
                resolveAllState.batch = clampResolveAllBatch( Math.floor( resolveAllState.batch / 2 ) || 1 );
                const currentDelay = ( typeof resolveAllState.delay === 'number' && resolveAllState.delay >= 0 )
                    ? resolveAllState.delay
                    : RESOLVE_ALL_DELAY;
                resolveAllState.delay = Math.min(
                    MAX_RESOLVE_ALL_DELAY,
                    currentDelay > 0 ? currentDelay * 2 : RESOLVE_ALL_DELAY * 2
                );
                
                updateResolveAllLabel( $btn );
                resolveAllState.inFlight = false;
                scheduleResolveAllStep( $btn );
            } else {
                // Max retries reached or non-retryable error
                const finalMessage = message || 'An error occurred while resolving redirects.';
                const detailedMessage = resolveAllRetryCount >= MAX_RETRY_ATTEMPTS 
                    ? finalMessage + ' Maximum retry attempts reached. Please try again later.'
                    : finalMessage;
                    
                alert( detailedMessage );
                
                // Clean up and reset
                resolveAllState = null;
                resolveAllRetryCount = 0;
                resetResolveAllTimer();
                $btn.prop( 'disabled', false );
                $btn.text( 'Resolve All' );
            }
        }

        function runResolveAllStep( $btn ) {
            if ( ! resolveAllState || resolveAllState.inFlight ) {
                return;
            }
            resolveAllState.inFlight = true;
            $.ajax( {
                url: ( window.RFXAdmin && RFXAdmin.ajax_url ) || ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'rfx_resolve_all',
                    nonce: resolveAllState.nonce,
                    token: resolveAllState.token || '',
                    cursor: resolveAllState.cursor,
                    batch: resolveAllState.batch,
                    processed: resolveAllState.processed,
                    total: resolveAllState.total,
                },
            } ).done( function ( res ) {
                if ( res && res.success ) {
                    const data = res.data || {};

                    // Reset retry count on successful request
                    resolveAllRetryCount = 0;
                    resolveAllState.errorNotified = false;
                    resolveAllState.batch = clampResolveAllBatch( resolveAllState.batch );

                    if ( typeof data.total === 'number' ) {
                        resolveAllState.total = data.total;
                    }
                    if ( typeof data.cursor === 'number' ) {
                        resolveAllState.cursor = data.cursor;
                    }
                    if ( typeof data.processed_step === 'number' && data.processed_step > 0 ) {
                        resolveAllState.processed += data.processed_step;
                    } else if ( typeof data.processed === 'number' && data.processed > resolveAllState.processed ) {
                        resolveAllState.processed = data.processed;
                    }
                    if ( resolveAllState.total > 0 ) {
                        resolveAllState.processed = Math.min( resolveAllState.processed, resolveAllState.total );
                    }
                    if ( data.token ) {
                        resolveAllState.token = data.token;
                    }

                    applyResolveAllTimingHints( data );
                    updateResolveAllLabel( $btn );

                    if ( data.done ) {
                        resolveAllState = null;
                        resetResolveAllTimer();
                        window.location.hash = '#pending';
                        window.location.reload();
                        return;
                    }

                    scheduleResolveAllStep( $btn );
                } else {
                    const message = ( res && res.data && res.data.message )
                        ? res.data.message
                        : 'An error occurred while resolving redirects.';
                    const isRetryable = ! ( res && res.data && ( res.data.code === 403 || res.data.code === 400 ) );
                    handleResolveAllError( $btn, message, isRetryable );
                }
            } ).fail( function ( jqXHR, textStatus, errorThrown ) {
                let message = 'Server error while resolving redirects.';
                let isRetryable = true;
                
                // Enhanced error logging
                console.error( '[LinkSentinel] AJAX Request Failed' );
                console.error( '  Status:', jqXHR.status );
                console.error( '  Status Text:', textStatus );
                console.error( '  Error Thrown:', errorThrown );
                console.error( '  Response Text:', jqXHR.responseText );
                
                // Try to parse JSON error response
                let parsedError = null;
                try {
                    if ( jqXHR.responseText ) {
                        parsedError = JSON.parse( jqXHR.responseText );
                        if ( parsedError && parsedError.data && parsedError.data.message ) {
                            message = parsedError.data.message;
                        }
                    }
                } catch ( e ) {
                    console.error( '  Could not parse error response as JSON' );
                }
                
                // Check for specific error conditions
                if ( jqXHR.status === 0 ) {
                    message = 'Network error. Please check your connection and try again.';
                } else if ( jqXHR.status === 403 ) {
                    message = 'Permission denied. Please check your user permissions.';
                    isRetryable = false;
                } else if ( jqXHR.status === 429 ) {
                    message = 'Too many requests. Please wait a minute and try again.';
                } else if ( jqXHR.status >= 500 ) {
                    message = 'Server error (HTTP ' + jqXHR.status + '). The server may be experiencing issues.';
                    // Check for PHP fatal errors in response
                    if ( jqXHR.responseText && ( jqXHR.responseText.indexOf( 'Fatal error' ) !== -1 || jqXHR.responseText.indexOf( 'Parse error' ) !== -1 ) ) {
                        message = 'PHP error detected. Check server error logs for details.';
                        console.error( '[LinkSentinel] PHP Error detected in response' );
                    }
                }
                
                handleResolveAllError( $btn, message, isRetryable );
            } ).always( function () {
                if ( resolveAllState ) {
                    resolveAllState.inFlight = false;
                }
            } );
        }

        $( document ).on( 'click', '#rfx-resolve-all', function ( e ) {
            e.preventDefault();
            const $btn  = $( this );
            const nonce = $btn.data( 'nonce' );
            const initialTotalAttr = $btn.data( 'total' );
            const initialTotal = ( typeof initialTotalAttr === 'number' || typeof initialTotalAttr === 'string' )
                ? parseInt( initialTotalAttr, 10 )
                : 0;
            if ( ! nonce ) {
                return;
            }
            if ( resolveAllState ) {
                return;
            }
            resolveAllState = {
                nonce: nonce,
                token: null,
                cursor: 0,
                processed: 0,
                total: ! isNaN( initialTotal ) && initialTotal > 0 ? initialTotal : 0,
                inFlight: false,
                originalText: $btn.text(),
                batch: RESOLVE_ALL_BATCH,
                delay: RESOLVE_ALL_DELAY,
                errorNotified: false,
            };
            resolveAllRetryCount = 0; // Reset retry count
            $btn.prop( 'disabled', true );
            updateResolveAllLabel( $btn );
            scheduleResolveAllStep( $btn, 0 );
        } );

        /*
         * Change a broken link directly from the Broken Links table.
         * When the user clicks the "Change" link in the dedicated column,
         * we dynamically replace the link with an inline form.  The form
         * consists of a text input (pre‑filled with the original URL) and
         * a "Change" button.  Submitting the form sends an AJAX request
         * to rfx_change_link.  On success the page reloads and the item
         * appears in the Resolved list.
         */
        $( document ).on( 'click', '.rfx-change-inline', function ( e ) {
            e.preventDefault();
            const $link   = $( this );
            const id      = $link.data( 'id' );
            const nonce   = $link.data( 'nonce' );
            const origUrl = $link.data( 'original-url' );
            if ( ! id || ! nonce ) {
                return;
            }
            const $td = $link.closest( 'td' );
            // If an editor is already present, do nothing to avoid duplicates.
            if ( $td.find( '.rfx-change-editor' ).length ) {
                return;
            }
            // Build inline editor elements.
            const $container = $( '<span class="rfx-change-editor" />' );
            const $input     = $( '<input type="text" class="regular-text" style="width:65%; margin-right:6px;" />' ).val( origUrl );
            const $button    = $( '<button type="button" class="button button-primary">' + 'Change' + '</button>' );
            // Replace the cell contents with the editor.
            $container.append( $input ).append( $button );
            $td.data( 'orig-html', $td.html() );
            $td.empty().append( $container );
            // Handle Change button click.
            $button.on( 'click', function () {
                const newUrl = $.trim( $input.val() );
                if ( ! newUrl ) {
                    alert( 'Please enter a URL or slug.' );
                    return;
                }
                // Disable UI during processing.
                $button.prop( 'disabled', true ).text( 'Changing…' );
                $.ajax( {
                    url: ( window.RFXAdmin && RFXAdmin.ajax_url ) || ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'rfx_change_link',
                        id: id,
                        nonce: nonce,
                        new_url: newUrl
                    },
                } ).done( function ( res ) {
                    if ( res && res.success ) {
                        if ( res.data && res.data.auto_resolved ) {
                            alert( res.data.message );
                        }
                        window.location.hash = '#broken';
                        window.location.reload();
                    } else {
                        if ( res && res.data && res.data.message ) {
                            alert( res.data.message );
                        } else {
                            alert( 'An error occurred while updating the link.' );
                        }
                        $button.prop( 'disabled', false ).text( 'Change' );
                    }
                } ).fail( function () {
                    alert( 'Server error while updating the link.' );
                    $button.prop( 'disabled', false ).text( 'Change' );
                } );
            } );
        } );

        /*
         * Resolve a single pending redirect from the Pending Redirects table.
         * When the user clicks "Resolve Now", we send an AJAX request to
         * rfx_resolve_link to update the post content with the final URL.
         */
        $( document ).on( 'click', '.rfx-resolve-link', function ( e ) {
            e.preventDefault();
            const $link = $( this );
            const id    = $link.data( 'id' );
            const nonce = $link.data( 'nonce' );
            if ( ! id || ! nonce ) {
                return;
            }
            // Disable the link to prevent double-clicks.
            $link.css( 'pointer-events', 'none' ).text( 'Resolving…' );
            $.ajax( {
                url: ( window.RFXAdmin && RFXAdmin.ajax_url ) || ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'rfx_resolve_link',
                    id: id,
                    nonce: nonce
                },
            } ).done( function ( res ) {
                if ( res && res.success ) {
                    if ( res.data && res.data.auto_resolved ) {
                        alert( res.data.message );
                    }
                    window.location.hash = '#pending';
                    window.location.reload();
                } else {
                    if ( res && res.data && res.data.message ) {
                        alert( res.data.message );
                    } else {
                        alert( 'An error occurred while resolving the link.' );
                    }
                    $link.css( 'pointer-events', '' ).text( 'Resolve Now' );
                }
            } ).fail( function () {
                alert( 'Server error while resolving the link.' );
                $link.css( 'pointer-events', '' ).text( 'Resolve Now' );
            } );
        } );
    } );
})( jQuery );
