/* =============================================================================
 * GenD Society — Member Calendar (entry)
 *
 * Phase 26 Plan 01 — SCAFFOLD ONLY. Mounts a placeholder into #gs-calendar-app
 * and locks the normalized event-object contract. Phase 26 Plan 02 replaces the
 * DOMContentLoaded body with the interactive month/week/day renderer (using mock
 * data shaped to the contract below). Phase 27's gs/v1/calendar/events REST will
 * emit exactly this shape — DO NOT change the shape; both 26-02 and Phase 27
 * depend on it.
 * ========================================================================== */

(function () {
	'use strict';

	/**
	 * Calendar event — LOCKED normalized shape.
	 * Phase 27 (gs/v1/calendar/events contract — do not change shape):
	 * {
	 *   id:        string,                                   // stable unique id
	 *   source:    'projects' | 'campaigns' | 'meetings',    // origin subsystem
	 *   type:      string,                                   // source-specific kind
	 *   title:     string,
	 *   start:     ISO8601-string,                           // event start
	 *   end:       ISO8601-string | null,                    // null => point-in-time
	 *   all_day:   boolean,
	 *   color:     string,                                   // hex; defaults to source color
	 *   status:    string,                                   // source-specific status
	 *   url:       string | null,                            // deep-link or null
	 *   busy:      boolean                                   // blocks availability
	 * }
	 */

	/** Source registry — label + accent color per event source. */
	var GS_CAL_SOURCES = {
		projects:  { label: 'Projects',  color: '#89C2E0' },
		campaigns: { label: 'Campaigns', color: '#b608c9' },
		meetings:  { label: 'Meetings',  color: '#00ff88' }
	};

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('gs-calendar-app');
		if (!root) {
			return;
		}

		// Phase 26-01 placeholder. Phase 26-02 replaces everything below with the
		// glass grid renderer that consumes events shaped per the contract above.
		root.textContent = 'Calendar loading…';
	});

})();
