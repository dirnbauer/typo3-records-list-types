/**
 * Records List Types - View Switcher JavaScript
 *
 * Handles AJAX persistence of view mode preference and
 * provides smooth transitions between view modes.
 */

/**
 * Get the AJAX URL for saving view mode preference.
 *
 * @returns {string|null} The AJAX URL or null if not available
 */
function getAjaxUrl() {
    // Try TYPO3's AJAX URL registry
    if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
        if (TYPO3.settings.ajaxUrls['ajax_records_list_types_set_view_mode']) {
            return TYPO3.settings.ajaxUrls['ajax_records_list_types_set_view_mode'];
        }
    }

    // No AJAX URL available — caller handles null gracefully
    return null;
}

/**
 * Save the view mode preference via AJAX.
 *
 * @param {string} mode - The view mode ('list' or 'grid')
 * @returns {Promise<Object>} The response data
 */
export async function saveViewModePreference(mode) {
    const ajaxUrl = getAjaxUrl();

    if (!ajaxUrl) {
        console.warn('AJAX URL for view mode not found');
        return;
    }

    const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ mode: mode }),
        credentials: 'same-origin'
    });

    if (!response.ok) {
        throw new Error('HTTP error ' + response.status);
    }

    return response.json();
}

/**
 * Get the current view mode from the active toggle button.
 *
 * @returns {string|null} The current mode or null
 */
export function getCurrentMode() {
    const activeButton = document.querySelector('[data-gridview-toggle].active');
    return activeButton ? activeButton.dataset.gridviewToggle : null;
}

/**
 * Handle toggle button click.
 *
 * @param {Event} event - The click event
 */
async function handleToggleClick(event) {
    const button = event.currentTarget;
    const mode = button.dataset.gridviewToggle;

    if (!mode) {
        return;
    }

    // Save preference via AJAX (don't prevent navigation)
    try {
        await saveViewModePreference(mode);
    } catch (error) {
        console.warn('Failed to save view mode preference:', error);
    }

    // Dispatch custom event for other scripts to react to
    const customEvent = new CustomEvent('recordsListTypes:viewModeChanged', {
        detail: { mode: mode },
        bubbles: true
    });
    document.dispatchEvent(customEvent);
}

/**
 * Initialize the view switcher functionality.
 */
export function initViewSwitcher() {
    const toggleButtons = document.querySelectorAll('[data-gridview-toggle]');

    if (toggleButtons.length === 0) {
        return;
    }

    toggleButtons.forEach(function(button) {
        button.addEventListener('click', handleToggleClick);
    });
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initViewSwitcher);
} else {
    initViewSwitcher();
}

// Re-initialize after TYPO3 AJAX module loads
if (typeof TYPO3 !== 'undefined' && TYPO3.Backend) {
    document.addEventListener('typo3:module:loaded', initViewSwitcher);
}
