/**
 * Grid View Actions - ES Module for TYPO3 v14
 * 
 * Features:
 * - Drag-and-drop reordering with CSS visual indicators
 * - WCAG 2.1 compliant keyboard navigation for drag and drop
 * - Screen reader support with ARIA live regions
 * - Record actions (hide/show, delete, clipboard, info, history)
 * - Sorting controls (manual drag mode and field-based sorting)
 * - Client-side search filtering
 * - Scroll shadow detection for compact view
 */

class GridViewActions {
    constructor() {
        // Mouse drag state
        this.draggedCard = null;
        this.draggedWrapper = null;
        this.draggedTable = null;
        this.draggedUid = null;
        this.currentTarget = null;
        this.currentTargetWrapper = null;
        this.dropPosition = null;
        
        // Keyboard drag state
        this.isKeyboardDragMode = false;
        this.keyboardDragCard = null;
        this.keyboardDragWrapper = null;
        this.keyboardTargetIndex = null;
        this.keyboardOriginalIndex = null;
        
        // Live region for screen reader announcements
        this.liveRegion = null;
        
        // TYPO3 handlers and modules
        this.AjaxDataHandler = null;
        this.Modal = null;
        this.Icons = null;
        
        // Pre-load TYPO3 modules
        this.loadTYPO3Modules();
    }
    
    /**
     * Pre-load TYPO3 backend modules for better performance
     */
    async loadTYPO3Modules() {
        // Load AjaxDataHandler
        try {
            const ajaxModule = await import('@typo3/backend/ajax-data-handler.js');
            this.AjaxDataHandler = ajaxModule.default;
        } catch (e) {
            // AjaxDataHandler not available, using fetch fallback
        }
        
        // Load Modal for delete confirmations
        try {
            const modalModule = await import('@typo3/backend/modal.js');
            this.Modal = modalModule.default;
        } catch (e) {
            // Modal not available, will use native confirm() fallback
        }
        
        // Load Icons for icon replacement
        try {
            const iconsModule = await import('@typo3/backend/icons.js');
            this.Icons = iconsModule.default;
        } catch (e) {
            // Icons module not available
        }
    }
    
    init() {
        this.initializeLiveRegion();
        this.initializeRecordActions();
        this.initializeDragAndDrop();
        this.initializeKeyboardDragDrop();
        this.initializeSorting();
        this.initializeSearch();
        this.initializeScrollShadows();
        this.initializePaginationInputs();
        this.initializeCompactDropdowns();
        this.initializeClipboardSelectionActions();
    }

    // =========================================================================
    // Screen Reader Support
    // =========================================================================

    /**
     * Initialize the ARIA live region for screen reader announcements
     */
    initializeLiveRegion() {
        this.liveRegion = document.getElementById('gridview-live-region');
        if (!this.liveRegion) {
            // Create live region if it doesn't exist in the HTML
            this.liveRegion = document.createElement('div');
            this.liveRegion.id = 'gridview-live-region';
            this.liveRegion.className = 'visually-hidden';
            this.liveRegion.setAttribute('aria-live', 'polite');
            this.liveRegion.setAttribute('aria-atomic', 'true');
            this.liveRegion.setAttribute('role', 'status');
            document.body.appendChild(this.liveRegion);
        }
    }

    /**
     * Announce a message to screen readers via the live region
     * @param {string} message - The message to announce
     */
    announce(message) {
        if (this.liveRegion) {
            // Clear and re-set to ensure announcement
            this.liveRegion.textContent = '';
            setTimeout(() => {
                this.liveRegion.textContent = message;
            }, 50);
        }
    }

    // =========================================================================
    // Mouse Drag and Drop
    // =========================================================================

    /**
     * Initialize mouse-based drag and drop
     */
    initializeDragAndDrop() {
        const cards = document.querySelectorAll('.gridview-card[draggable="true"]');
        
        if (cards.length === 0) {
            return;
        }
        
        cards.forEach((card) => {
            card.addEventListener('dragstart', this.onDragStart.bind(this));
            card.addEventListener('dragend', this.onDragEnd.bind(this));
            card.addEventListener('dragover', this.onDragOver.bind(this));
            card.addEventListener('dragleave', this.onDragLeave.bind(this));
            card.addEventListener('drop', this.onDrop.bind(this));
        });
        
        // Add event handlers for end dropzones (drop after last item)
        const endDropzones = document.querySelectorAll('.gridview-end-dropzone');
        endDropzones.forEach((dropzone) => {
            dropzone.addEventListener('dragover', this.onEndDropzoneOver.bind(this));
            dropzone.addEventListener('dragleave', this.onEndDropzoneLeave.bind(this));
            dropzone.addEventListener('drop', this.onEndDropzoneDrop.bind(this));
        });
    }
    
    onDragStart(e) {
        const card = e.target.closest('.gridview-card');
        if (!card) {
            return;
        }
        
        const wrapper = card.closest('.gridview-card-wrapper');
        
        this.draggedCard = card;
        this.draggedWrapper = wrapper;
        this.draggedTable = card.dataset.table;
        this.draggedUid = card.dataset.uid;
        
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', this.draggedUid);
        
        // Update ARIA state
        card.setAttribute('aria-grabbed', 'true');
        
        // Add visual feedback after a tick (so drag image is captured)
        setTimeout(() => {
            card.classList.add('gridview-dragging');
            document.body.classList.add('is-dragging');
        }, 0);
    }
    
    onDragEnd(e) {
        // Clean up all states
        document.querySelectorAll('.gridview-dragging').forEach(el => el.classList.remove('gridview-dragging'));
        document.querySelectorAll('.gridview-drop-before, .gridview-drop-after').forEach(el => {
            el.classList.remove('gridview-drop-before', 'gridview-drop-after');
        });
        document.querySelectorAll('.gridview-end-dropzone').forEach(el => {
            el.classList.remove('gridview-drop-active');
        });
        document.body.classList.remove('is-dragging');
        
        // Reset ARIA state
        if (this.draggedCard) {
            this.draggedCard.setAttribute('aria-grabbed', 'false');
        }
        
        this.draggedCard = null;
        this.draggedWrapper = null;
        this.draggedTable = null;
        this.draggedUid = null;
        this.currentTarget = null;
        this.currentTargetWrapper = null;
        this.dropPosition = null;
    }
    
    /**
     * Handle dragover on end dropzone
     */
    onEndDropzoneOver(e) {
        e.preventDefault();
        
        const dropzone = e.target.closest('.gridview-end-dropzone');
        if (!dropzone) return;
        if (dropzone.dataset.table !== this.draggedTable) return;
        
        e.dataTransfer.dropEffect = 'move';
        
        // Clear other drop indicators
        document.querySelectorAll('.gridview-drop-before, .gridview-drop-after').forEach(el => {
            el.classList.remove('gridview-drop-before', 'gridview-drop-after');
        });
        
        // Activate this dropzone
        dropzone.classList.add('gridview-drop-active');
        this.currentTarget = null;
        this.currentTargetWrapper = null;
        this.dropPosition = 'end';
    }
    
    /**
     * Handle dragleave on end dropzone
     */
    onEndDropzoneLeave(e) {
        const dropzone = e.target.closest('.gridview-end-dropzone');
        if (!dropzone) return;
        
        // Only clear if actually leaving (not entering a child)
        const related = e.relatedTarget;
        if (related && dropzone.contains(related)) return;
        
        dropzone.classList.remove('gridview-drop-active');
        if (this.dropPosition === 'end') {
            this.dropPosition = null;
        }
    }
    
    /**
     * Handle drop on end dropzone
     */
    onEndDropzoneDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const dropzone = e.target.closest('.gridview-end-dropzone');
        if (!dropzone) return;
        if (dropzone.dataset.table !== this.draggedTable) return;
        
        // Get the last UID - we want to move after this element
        const lastUid = dropzone.dataset.lastUid;
        if (!lastUid) {
            console.error('[GridView] No lastUid found on end dropzone');
            return;
        }
        
        // Move after the last element (negative UID)
        const moveTarget = '-' + lastUid;
        
        this.executeMove(this.draggedTable, this.draggedUid, moveTarget);
    }
    
    onDragOver(e) {
        e.preventDefault();
        
        const card = e.target.closest('.gridview-card');
        if (!card || card === this.draggedCard) return;
        if (card.dataset.table !== this.draggedTable) return;
        
        const wrapper = card.closest('.gridview-card-wrapper');
        
        e.dataTransfer.dropEffect = 'move';
        
        // Calculate position: top half = before, bottom half = after
        const rect = card.getBoundingClientRect();
        const y = e.clientY - rect.top;
        const position = y < rect.height / 2 ? 'before' : 'after';
        
        // Only update if changed
        if (this.currentTargetWrapper !== wrapper || this.dropPosition !== position) {
            // Clear previous
            document.querySelectorAll('.gridview-drop-before, .gridview-drop-after').forEach(el => {
                el.classList.remove('gridview-drop-before', 'gridview-drop-after');
            });
            
            // Set new - apply classes to wrapper, not card
            this.currentTarget = card;
            this.currentTargetWrapper = wrapper;
            this.dropPosition = position;
            wrapper.classList.add(`gridview-drop-${position}`);
        }
    }
    
    onDragLeave(e) {
        const card = e.target.closest('.gridview-card');
        if (!card) return;
        
        const wrapper = card.closest('.gridview-card-wrapper');
        
        // Only clear if actually leaving (not entering a child)
        const related = e.relatedTarget;
        if (related && card.contains(related)) return;
        
        wrapper.classList.remove('gridview-drop-before', 'gridview-drop-after');
        if (this.currentTargetWrapper === wrapper) {
            this.currentTarget = null;
            this.currentTargetWrapper = null;
            this.dropPosition = null;
        }
    }
    
    onDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const targetCard = e.target.closest('.gridview-card');
        
        if (!targetCard) {
            return;
        }
        if (targetCard === this.draggedCard) {
            return;
        }
        if (targetCard.dataset.table !== this.draggedTable) {
            return;
        }
        
        const targetUid = targetCard.dataset.uid;
        const targetPid = targetCard.dataset.pid;
        
        // Default to 'after' if position wasn't determined
        const position = this.dropPosition || 'after';
        
        // Calculate TYPO3 move target
        const moveTarget = this.calculateMoveTarget(targetCard, position, targetUid, targetPid);
        
        this.executeMove(this.draggedTable, this.draggedUid, moveTarget);
    }

    // =========================================================================
    // Keyboard Drag and Drop (WCAG 2.1 Compliant)
    // =========================================================================

    /**
     * Initialize keyboard-accessible drag and drop
     */
    initializeKeyboardDragDrop() {
        const dragHandles = document.querySelectorAll('.gridview-card__drag[role="button"]');
        
        if (dragHandles.length === 0) {
            return;
        }
        
        dragHandles.forEach((handle) => {
            // Handle keyboard events on drag handle
            handle.addEventListener('keydown', this.onDragHandleKeydown.bind(this));
        });
        
        // Global keyboard handler for arrow keys during drag mode
        document.addEventListener('keydown', this.onGlobalKeydown.bind(this));
    }

    /**
     * Handle keydown on drag handle (Space/Enter to start drag)
     */
    onDragHandleKeydown(e) {
        if (e.key === ' ' || e.key === 'Enter') {
            e.preventDefault();
            
            const handle = e.target.closest('.gridview-card__drag');
            const card = handle?.closest('.gridview-card');
            const wrapper = card?.closest('.gridview-card-wrapper');
            
            if (!card || !wrapper) return;
            
            if (this.isKeyboardDragMode && this.keyboardDragCard === card) {
                // Already in drag mode for this card - drop it
                this.keyboardDrop();
            } else if (this.isKeyboardDragMode) {
                // Cancel current drag and start new one
                this.keyboardCancelDrag();
                this.keyboardStartDrag(card, wrapper, handle);
            } else {
                // Start keyboard drag
                this.keyboardStartDrag(card, wrapper, handle);
            }
        } else if (e.key === 'Escape' && this.isKeyboardDragMode) {
            e.preventDefault();
            this.keyboardCancelDrag();
        }
    }

    /**
     * Handle global keydown for arrow navigation during keyboard drag
     * Note: wrappers.length is the number of card positions
     * Index wrappers.length represents the "end" position (after last card)
     */
    onGlobalKeydown(e) {
        if (!this.isKeyboardDragMode) return;
        
        const grid = this.keyboardDragWrapper?.closest('.gridview-card-grid');
        if (!grid) return;
        
        const wrappers = Array.from(grid.querySelectorAll('.gridview-card-wrapper'));
        const hasEndDropzone = grid.querySelector('.gridview-end-dropzone') !== null;
        const maxIndex = hasEndDropzone ? wrappers.length : wrappers.length - 1;
        const currentIndex = this.keyboardTargetIndex;
        let newIndex = currentIndex;
        
        switch (e.key) {
            case 'ArrowUp':
            case 'ArrowLeft':
                e.preventDefault();
                newIndex = Math.max(0, currentIndex - 1);
                break;
            case 'ArrowDown':
            case 'ArrowRight':
                e.preventDefault();
                newIndex = Math.min(maxIndex, currentIndex + 1);
                break;
            case 'Home':
                e.preventDefault();
                newIndex = 0;
                break;
            case 'End':
                e.preventDefault();
                newIndex = maxIndex;
                break;
            case 'Escape':
                e.preventDefault();
                this.keyboardCancelDrag();
                return;
            case ' ':
            case 'Enter':
                e.preventDefault();
                this.keyboardDrop();
                return;
            default:
                return;
        }
        
        if (newIndex !== currentIndex) {
            this.keyboardMoveTo(newIndex, wrappers);
        }
    }

    /**
     * Start keyboard drag mode
     */
    keyboardStartDrag(card, wrapper, handle) {
        const grid = wrapper.closest('.gridview-card-grid');
        if (!grid) return;
        
        const wrappers = Array.from(grid.querySelectorAll('.gridview-card-wrapper'));
        const index = wrappers.indexOf(wrapper);
        
        this.isKeyboardDragMode = true;
        this.keyboardDragCard = card;
        this.keyboardDragWrapper = wrapper;
        this.keyboardOriginalIndex = index;
        this.keyboardTargetIndex = index;
        
        // Visual feedback
        card.classList.add('gridview-keyboard-dragging');
        card.setAttribute('aria-grabbed', 'true');
        
        // Announce to screen reader
        const title = card.dataset.recordTitle || 'Item';
        this.announce(`${title} grabbed. Use arrow keys to move. Press Space or Enter to drop, Escape to cancel.`);
    }

    /**
     * Move to a new position during keyboard drag
     * Note: newIndex can be wrappers.length to indicate "end" position
     */
    keyboardMoveTo(newIndex, wrappers) {
        const grid = this.keyboardDragWrapper?.closest('.gridview-card-grid');
        
        // Clear previous indicators
        document.querySelectorAll('.gridview-drop-before, .gridview-drop-after').forEach(el => {
            el.classList.remove('gridview-drop-before', 'gridview-drop-after');
        });
        document.querySelectorAll('.gridview-end-dropzone').forEach(el => {
            el.classList.remove('gridview-keyboard-target');
        });
        
        this.keyboardTargetIndex = newIndex;
        
        // Check if targeting end position
        if (newIndex >= wrappers.length) {
            // End position - highlight the end dropzone
            const endDropzone = grid?.querySelector('.gridview-end-dropzone');
            if (endDropzone) {
                endDropzone.classList.add('gridview-keyboard-target');
            }
            // Announce "end" position
            this.announce(`End position (after last item)`);
        } else {
            // Show drop indicator on target wrapper
            const targetWrapper = wrappers[newIndex];
            if (targetWrapper && targetWrapper !== this.keyboardDragWrapper) {
                // Determine if dropping before or after
                if (newIndex < this.keyboardOriginalIndex) {
                    targetWrapper.classList.add('gridview-drop-before');
                } else {
                    targetWrapper.classList.add('gridview-drop-after');
                }
            }
            // Announce position
            this.announce(`Position ${newIndex + 1} of ${wrappers.length}`);
        }
    }

    /**
     * Drop the item at the current keyboard target position
     * Note: targetIndex can be wrappers.length to indicate "end" position
     */
    keyboardDrop() {
        if (!this.isKeyboardDragMode || !this.keyboardDragCard) return;
        
        const grid = this.keyboardDragWrapper.closest('.gridview-card-grid');
        if (!grid) {
            this.keyboardCancelDrag();
            return;
        }
        
        const wrappers = Array.from(grid.querySelectorAll('.gridview-card-wrapper'));
        const targetIndex = this.keyboardTargetIndex;
        const originalIndex = this.keyboardOriginalIndex;
        
        // If dropped at same position, just cancel
        if (targetIndex === originalIndex) {
            this.keyboardCancelDrag();
            return;
        }
        
        const draggedCard = this.keyboardDragCard;
        const table = draggedCard.dataset.table;
        const uid = draggedCard.dataset.uid;
        
        let moveTarget;
        let announcePosition;
        
        // Check if dropping at end position
        if (targetIndex >= wrappers.length) {
            // End position - move after the last element
            const lastUid = grid.dataset.lastUid;
            if (!lastUid) {
                console.error('[GridView] No lastUid found on grid');
                this.keyboardCancelDrag();
                return;
            }
            moveTarget = '-' + lastUid;
            announcePosition = 'end';
        } else {
            // Get the target card and calculate move target
            const targetWrapper = wrappers[targetIndex];
            const targetCard = targetWrapper?.querySelector('.gridview-card');
            
            if (!targetCard) {
                this.keyboardCancelDrag();
                return;
            }
            
            const targetUid = targetCard.dataset.uid;
            const targetPid = targetCard.dataset.pid;
            
            // Determine position (before or after target)
            const position = targetIndex < originalIndex ? 'before' : 'after';
            
            // Calculate TYPO3 move target
            moveTarget = this.calculateMoveTarget(targetCard, position, targetUid, targetPid);
            announcePosition = `position ${targetIndex + 1}`;
        }
        
        // Clean up keyboard drag state first
        this.keyboardCleanup();
        
        // Announce and execute move
        this.announce(`Item moved to ${announcePosition}`);
        this.executeMove(table, uid, moveTarget);
    }

    /**
     * Cancel keyboard drag operation
     */
    keyboardCancelDrag() {
        if (!this.isKeyboardDragMode) return;
        
        // Focus back on the drag handle
        const handle = this.keyboardDragCard?.querySelector('.gridview-card__drag');
        
        this.keyboardCleanup();
        
        this.announce('Reorder cancelled');
        
        // Return focus to handle
        if (handle) {
            handle.focus();
        }
    }

    /**
     * Clean up keyboard drag state
     */
    keyboardCleanup() {
        // Clear visual states
        document.querySelectorAll('.gridview-keyboard-dragging').forEach(el => {
            el.classList.remove('gridview-keyboard-dragging');
            el.setAttribute('aria-grabbed', 'false');
        });
        document.querySelectorAll('.gridview-drop-before, .gridview-drop-after').forEach(el => {
            el.classList.remove('gridview-drop-before', 'gridview-drop-after');
        });
        document.querySelectorAll('.gridview-end-dropzone').forEach(el => {
            el.classList.remove('gridview-keyboard-target', 'gridview-drop-active');
        });
        
        // Reset state
        this.isKeyboardDragMode = false;
        this.keyboardDragCard = null;
        this.keyboardDragWrapper = null;
        this.keyboardOriginalIndex = null;
        this.keyboardTargetIndex = null;
    }

    // =========================================================================
    // Shared Move Logic
    // =========================================================================

    /**
     * Calculate the TYPO3 move target based on position
     */
    calculateMoveTarget(targetCard, position, targetUid, targetPid) {
        if (position === 'after') {
            // Move after target = negative target UID
            return '-' + targetUid;
        } else {
            // Move before target = find previous card (skipping the dragged card)
            const wrapper = targetCard.closest('.gridview-card-wrapper');
            let prevWrapper = wrapper?.previousElementSibling;
            let prevCard = null;
            const draggedCard = this.draggedCard || this.keyboardDragCard;
            const draggedTable = this.draggedTable || draggedCard?.dataset.table;
            
            // Skip the dragged card if it's the previous sibling
            while (prevWrapper) {
                const candidate = prevWrapper.querySelector('.gridview-card');
                if (candidate && candidate !== draggedCard && candidate.dataset.table === draggedTable) {
                    prevCard = candidate;
                    break;
                }
                prevWrapper = prevWrapper.previousElementSibling;
            }
            
            if (prevCard) {
                return '-' + prevCard.dataset.uid;
            } else {
                // First position - use page ID
                return targetPid;
            }
        }
    }
    
    async executeMove(table, uid, target) {
        // Visual feedback
        const card = this.draggedCard || this.keyboardDragCard;
        if (card) {
            card.style.opacity = '0.3';
        }
        
        // Validate inputs
        if (!table || !uid || target === undefined || target === null) {
            console.error('[GridView] Invalid move parameters');
            if (card) card.style.opacity = '';
            return;
        }
        
        // Ensure target is a string
        const targetStr = String(target);
        
        // Get the AJAX URL
        const url = TYPO3?.settings?.ajaxUrls?.record_process;
        if (!url) {
            console.error('[GridView] No AJAX URL available');
            if (card) card.style.opacity = '';
            return;
        }
        
        // Build URL with proper parameters
        const fullUrl = new URL(url, window.location.origin);
        fullUrl.searchParams.set(`cmd[${table}][${uid}][move]`, targetStr);
        
        try {
            const response = await fetch(fullUrl.toString(), { 
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                console.error('[GridView] HTTP Error:', response.status);
                if (card) card.style.opacity = '';
                return;
            }
            
            const data = await response.json();
            
            if (data.hasErrors) {
                console.error('[GridView] DataHandler errors:', data.messages);
                this.showNotification('Move failed', data.messages?.[0]?.message || 'Unknown error', 'error');
                if (card) card.style.opacity = '';
            } else {
                window.location.reload();
            }
        } catch (err) {
            console.error('[GridView] Network error:', err);
            if (card) card.style.opacity = '';
        }
    }

    // =========================================================================
    // Record Actions
    // =========================================================================

    /**
     * Record actions (hide/show, delete)
     */
    initializeRecordActions() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-gridview-action]');
            if (!btn) return;
            
            e.preventDefault();
            
            const action = btn.dataset.gridviewAction;
            const table = btn.dataset.table;
            const uid = btn.dataset.uid;
            
            switch (action) {
                case 'hide':
                case 'show':
                    this.toggleHidden(table, uid, action, btn.dataset.hiddenField || 'hidden');
                    break;
                case 'delete':
                    this.deleteRecord(table, uid, btn);
                    break;
                case 'copy':
                    this.clipboardAction(table, uid, 'copy');
                    break;
                case 'cut':
                    this.clipboardAction(table, uid, 'cut');
                    break;
                case 'info':
                    this.showInfo(table, uid);
                    break;
                case 'history':
                    this.showHistory(table, uid);
                    break;
            }
        });
    }
    
    toggleHidden(table, uid, action, field) {
        const url = TYPO3?.settings?.ajaxUrls?.record_process;
        if (!url) {
            console.error('[GridView] No AJAX URL available');
            return;
        }
        
        const fullUrl = new URL(url, window.location.origin);
        fullUrl.searchParams.set(`data[${table}][${uid}][${field}]`, action === 'hide' ? '1' : '0');
        
        fetch(fullUrl.toString(), { 
            method: 'GET',
            credentials: 'same-origin',
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' 
            }
        })
            .then(r => r.json())
            .then(data => {
                if (data.hasErrors) {
                    const msg = data.messages?.[0]?.message || 'Unknown error';
                    this.showNotification('Update failed', msg, 'error');
                } else {
                    window.location.reload();
                }
            })
            .catch(err => {
                console.error('[GridView] Toggle error:', err);
                this.showNotification('Request failed', err.message, 'error');
            });
    }
    
    /**
     * Delete a record with TYPO3 Modal confirmation
     */
    async deleteRecord(table, uid, btn) {
        const card = btn.closest('.gridview-card');
        const title = card?.querySelector('.gridview-card__title')?.textContent?.trim() || uid;
        
        // Use TYPO3 Modal if available, otherwise fall back to native confirm
        const confirmed = await this.confirmDelete(title);
        if (!confirmed) return;
        
        this.executeDelete(table, uid, card);
    }
    
    /**
     * Show delete confirmation dialog using TYPO3 Modal or native confirm
     * @param {string} title - The record title to display
     * @returns {Promise<boolean>} True if confirmed, false if cancelled
     */
    async confirmDelete(title) {
        // Try TYPO3 Modal first
        if (this.Modal) {
            return new Promise((resolve) => {
                this.Modal.confirm(
                    'Delete Record',
                    `Are you sure you want to delete "${title}"?`,
                    this.Modal.sizes.small,
                    [
                        {
                            text: 'Cancel',
                            active: true,
                            btnClass: 'btn-default',
                            trigger: (event, modal) => {
                                modal.hideModal();
                                resolve(false);
                            }
                        },
                        {
                            text: 'Delete',
                            btnClass: 'btn-danger',
                            trigger: (event, modal) => {
                                modal.hideModal();
                                resolve(true);
                            }
                        }
                    ]
                );
            });
        }
        
        // Fallback to native confirm
        return confirm(`Delete "${title}"?`);
    }
    
    /**
     * Execute the delete operation after confirmation.
     *
     * Uses TYPO3's AjaxDataHandler.process() which handles notifications
     * and events, then reloads the page to ensure consistent state
     * (record counts, pagination, empty tables).
     */
    executeDelete(table, uid, card) {
        // Animate out immediately for visual feedback
        const wrapper = card?.closest('.gridview-card-wrapper') || card;
        if (wrapper) {
            wrapper.style.transition = 'all 0.2s';
            wrapper.style.opacity = '0';
            wrapper.style.transform = 'scale(0.9)';
        }

        const params = { cmd: { [table]: { [uid]: { delete: 1 } } } };

        // Prefer TYPO3 AjaxDataHandler (handles notifications + events)
        if (this.AjaxDataHandler) {
            this.AjaxDataHandler.process(params).then(() => {
                window.location.reload();
            });
            return;
        }

        // Fallback: raw fetch if AjaxDataHandler is not available
        const url = TYPO3?.settings?.ajaxUrls?.record_process;
        if (!url) {
            console.error('[GridView] No AJAX URL available');
            return;
        }

        const fullUrl = new URL(url, window.location.origin);
        fullUrl.searchParams.set(`cmd[${table}][${uid}][delete]`, '1');

        fetch(fullUrl.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(r => r.json())
            .then(data => {
                if (data.hasErrors) {
                    const msg = data.messages?.[0]?.message || 'Unknown error';
                    this.showNotification('Delete failed', msg, 'error');
                    // Restore card visibility on error
                    if (wrapper) {
                        wrapper.style.opacity = '1';
                        wrapper.style.transform = '';
                    }
                } else {
                    window.location.reload();
                }
            })
            .catch(err => {
                console.error('[GridView] Delete error:', err);
                this.showNotification('Request failed', err.message, 'error');
                // Restore card visibility on error
                if (wrapper) {
                    wrapper.style.opacity = '1';
                    wrapper.style.transform = '';
                }
            });
    }

    /**
     * Clipboard action - copy or cut record to TYPO3 clipboard
     */
    async clipboardAction(table, uid, mode) {
        const url = TYPO3?.settings?.ajaxUrls?.clipboard_process;
        if (!url) {
            // Fallback: try alternative approach
            this.clipboardFallback(table, uid, mode);
            return;
        }
        
        const fullUrl = new URL(url, window.location.origin);
        
        // Set clipboard parameters
        fullUrl.searchParams.set(`CB[el][${table}|${uid}]`, '1');
        fullUrl.searchParams.set('CB[setCopyMode]', mode === 'copy' ? '1' : '0');
        
        try {
            const response = await fetch(fullUrl.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            await response.json();
            
            // Show notification
            this.showNotification(
                mode === 'copy' ? 'Copied to clipboard' : 'Cut to clipboard',
                `Record ${uid} from ${table}`,
                'success'
            );
            
            // Update the copy/cut icons in all cards to reflect clipboard state
            this.updateClipboardIcons(table, uid, mode);
            
            // Update clipboard panel if visible
            const clipboardPanel = document.querySelector('typo3-backend-clipboard-panel');
            if (clipboardPanel) {
                clipboardPanel.dispatchEvent(new Event('typo3:clipboard:update'));
            }
            
        } catch (err) {
            console.error('[GridView] Clipboard error:', err);
            this.showNotification('Clipboard action failed', err.message, 'error');
        }
    }
    
    /**
     * Update clipboard icons after copy/cut action
     * Changes icon to "release" variant and updates other cards
     */
    updateClipboardIcons(table, uid, mode) {
        // First, reset all copy/cut icons to default state
        document.querySelectorAll('[data-gridview-action="copy"], [data-gridview-action="cut"]').forEach(el => {
            const action = el.dataset.gridviewAction;
            const iconEl = el.querySelector('.icon, typo3-backend-icon');
            if (iconEl) {
                // Reset to default icons
                const defaultIcon = action === 'copy' ? 'actions-edit-copy' : 'actions-edit-cut';
                this.replaceIcon(iconEl, defaultIcon);
            }
            // Remove active class
            el.classList.remove('is-clipboard-active');
        });
        
        // Now set the active state for the copied/cut element
        const activeSelector = `[data-gridview-action="${mode}"][data-table="${table}"][data-uid="${uid}"]`;
        document.querySelectorAll(activeSelector).forEach(el => {
            const iconEl = el.querySelector('.icon, typo3-backend-icon');
            if (iconEl) {
                // Use release icon to indicate element is on clipboard
                const releaseIcon = mode === 'copy' ? 'actions-edit-copy-release' : 'actions-edit-cut-release';
                this.replaceIcon(iconEl, releaseIcon);
            }
            el.classList.add('is-clipboard-active');
        });
    }
    
    /**
     * Replace a TYPO3 icon element with a new icon.
     * Uses DOMParser instead of innerHTML for safer HTML parsing.
     */
    replaceIcon(iconEl, newIdentifier) {
        // For typo3-backend-icon web component
        if (iconEl.tagName === 'TYPO3-BACKEND-ICON') {
            iconEl.setAttribute('identifier', newIdentifier);
            return;
        }
        
        // For traditional span.icon elements, use pre-loaded Icons module
        const iconsModule = this.Icons;
        const parseAndReplace = (iconMarkup) => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(iconMarkup, 'text/html');
            const newIcon = doc.body.firstElementChild;
            if (newIcon && iconEl.parentNode) {
                iconEl.parentNode.replaceChild(
                    document.adoptNode(newIcon),
                    iconEl
                );
            }
        };

        if (iconsModule) {
            iconsModule.getIcon(newIdentifier, 'small')
                .then(parseAndReplace)
                .catch(() => {
                    // Fallback: just update data attribute
                    iconEl.dataset.identifier = newIdentifier;
                });
        } else {
            // Icons module not loaded, try dynamic import as fallback
            import('@typo3/backend/icons.js')
                .then(module => {
                    const Icons = module.default;
                    Icons.getIcon(newIdentifier, 'small').then(parseAndReplace);
                })
                .catch(() => {
                    // Fallback: just update data attribute if possible
                    iconEl.dataset.identifier = newIdentifier;
                });
        }
    }
    
    /**
     * Fallback for clipboard when AJAX URL is not available
     */
    clipboardFallback(table, uid, mode) {
        const url = new URL(window.location.href);
        url.searchParams.set(`CB[el][${table}|${uid}]`, '1');
        url.searchParams.set('CB[setCopyMode]', mode === 'copy' ? '1' : '0');
        window.location.href = url.toString();
    }
    
    /**
     * Show info popup for a record using TYPO3's InfoWindow
     */
    /**
     * Show info for a record in the content frame (like History).
     * Uses URL/URLSearchParams for safe URL construction.
     */
    showInfo(table, uid) {
        const returnUrl = window.location.pathname + window.location.search;
        
        // Get the ShowItem module URL from TYPO3 settings
        const moduleUrl = top?.TYPO3?.settings?.ShowItem?.moduleUrl;
        
        if (moduleUrl) {
            const infoUrl = new URL(moduleUrl, window.location.origin);
            infoUrl.searchParams.set('table', table);
            infoUrl.searchParams.set('uid', String(uid));
            infoUrl.searchParams.set('returnUrl', returnUrl);
            const infoUrlStr = infoUrl.toString();
            
            // Use Viewport.ContentContainer.setUrl() like History does
            import('@typo3/backend/viewport.js')
                .then(module => {
                    const Viewport = module.default || module;
                    if (Viewport?.ContentContainer?.setUrl) {
                        Viewport.ContentContainer.setUrl(infoUrlStr);
                    } else {
                        window.location.href = infoUrlStr;
                    }
                })
                .catch(() => {
                    window.location.href = infoUrlStr;
                });
        } else {
            // Fallback to InfoWindow popup if settings not available
            if (typeof top?.TYPO3?.InfoWindow?.showItem === 'function') {
                top.TYPO3.InfoWindow.showItem(table, uid);
            }
        }
    }
    
    /**
     * Show history for a record.
     * Uses URL/URLSearchParams for safe URL construction.
     * Uses TYPO3's Viewport.ContentContainer.setUrl() - same as core context menu.
     */
    showHistory(table, uid) {
        const element = `${table}:${uid}`;
        const returnUrl = window.location.pathname + window.location.search;
        
        // Get the history module URL from TYPO3 settings
        const moduleUrl = top?.TYPO3?.settings?.RecordHistory?.moduleUrl;
        
        if (moduleUrl) {
            const historyUrl = new URL(moduleUrl, window.location.origin);
            historyUrl.searchParams.set('element', element);
            historyUrl.searchParams.set('returnUrl', returnUrl);
            const historyUrlStr = historyUrl.toString();
            
            // Use Viewport.ContentContainer.setUrl() like TYPO3 core does
            import('@typo3/backend/viewport.js')
                .then(module => {
                    const Viewport = module.default || module;
                    if (Viewport?.ContentContainer?.setUrl) {
                        Viewport.ContentContainer.setUrl(historyUrlStr);
                    } else {
                        // Fallback
                        window.location.href = historyUrlStr;
                    }
                })
                .catch(() => {
                    window.location.href = historyUrlStr;
                });
        } else {
            // Fallback if settings not available
            console.warn('[GridView] RecordHistory.moduleUrl not found in TYPO3.settings');
        }
    }
    
    /**
     * Show notification using TYPO3's notification system
     */
    showNotification(title, message, severity = 'info') {
        import('@typo3/backend/notification.js')
            .then(module => {
                const Notification = module.default;
                switch (severity) {
                    case 'success':
                        Notification.success(title, message, 3);
                        break;
                    case 'error':
                        Notification.error(title, message, 5);
                        break;
                    case 'warning':
                        Notification.warning(title, message, 4);
                        break;
                    default:
                        Notification.info(title, message, 3);
                }
            })
            .catch(() => {
                // Fallback: console log
                console.log(`[GridView] ${severity.toUpperCase()}: ${title} - ${message}`);
            });
    }

    // =========================================================================
    // Sorting Controls
    // =========================================================================

    /**
     * Sorting controls - dropdown, direction toggle, and table header clicks
     */
    initializeSorting() {
        // Handle sort field dropdown changes (legacy partial support)
        document.querySelectorAll('[data-gridview-sort-field]').forEach(select => {
            select.addEventListener('change', () => {
                this.updateSorting(select);
            });
        });

        // Handle sort direction toggle button clicks (legacy partial support)
        // Note: New PHP-generated dropdowns use anchor links and don't need JavaScript
        document.querySelectorAll('[data-gridview-sort-toggle]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const currentDirection = btn.dataset.currentDirection;
                const newDirection = currentDirection === 'asc' ? 'desc' : 'asc';
                const tableName = btn.dataset.table;
                
                // Try to find select element in container
                const container = btn.closest('.gridview-sorting');
                const select = container?.querySelector('[data-gridview-sort-field]');
                
                if (select) {
                    // Legacy mode: use select element
                    this.updateSorting(select, newDirection);
                } else if (tableName) {
                    // New mode: build URL directly from button data
                    this.updateSortDirection(tableName, newDirection);
                }
            });
        });

        // Handle table header click-to-sort (compact view)
        document.querySelectorAll('.compactview-th--sortable[data-sort-field]').forEach(th => {
            const sortField = th.dataset.sortField;
            const currentSortField = th.dataset.currentSortField || '';
            
            if (sortField && sortField === currentSortField) {
                th.classList.add('is-active');
            }
            
            th.addEventListener('click', (e) => {
                e.preventDefault();
                
                const tableName = th.dataset.table;
                const currentDirection = th.dataset.sortDirection || 'asc';
                
                if (!sortField || !tableName) return;
                
                const isThisColumnSorted = currentSortField === sortField;
                let newDirection = 'asc';
                if (isThisColumnSorted) {
                    newDirection = currentDirection === 'asc' ? 'desc' : 'asc';
                }
                
                const url = new URL(window.location.href);
                url.searchParams.set(`sort[${tableName}][field]`, sortField);
                url.searchParams.set(`sort[${tableName}][direction]`, newDirection);
                
                window.location.href = url.toString();
            });
        });

        // Initialize scroll shadows for compact view tables
        this.initScrollShadows();
    }

    /**
     * Initialize scroll shadow indicators for compact view tables
     */
    initScrollShadows() {
        document.querySelectorAll('.compactview-table-wrapper').forEach(wrapper => {
            const updateShadows = () => {
                const scrollLeft = wrapper.scrollLeft;
                const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
                
                wrapper.classList.toggle('is-scrolled-left', scrollLeft > 5);
                wrapper.classList.toggle('is-scrolled-right', scrollLeft < maxScroll - 5);
            };
            
            wrapper.addEventListener('scroll', updateShadows, { passive: true });
            window.addEventListener('resize', updateShadows, { passive: true });
            updateShadows();
        });
    }

    /**
     * Build URL and navigate with per-table sorting parameters
     */
    updateSorting(selectElement, forcedDirection = null) {
        if (!selectElement) return;

        const tableName = selectElement.dataset.table;
        const sortField = selectElement.value;
        
        if (!tableName) {
            console.error('[GridView] No table name for sorting');
            return;
        }
        
        let sortDirection = forcedDirection;
        if (!sortDirection) {
            const container = selectElement.closest('.gridview-sorting');
            const toggleBtn = container?.querySelector('[data-gridview-sort-toggle]');
            sortDirection = toggleBtn?.dataset.currentDirection || 'asc';
        }

        const url = new URL(window.location.href);
        
        // Clear any old global sorting params
        url.searchParams.delete('sortField');
        url.searchParams.delete('sortDirection');
        
        // Update this table's sorting params
        if (sortField) {
            url.searchParams.set(`sort[${tableName}][field]`, sortField);
            url.searchParams.set(`sort[${tableName}][direction]`, sortDirection);
        } else {
            url.searchParams.delete(`sort[${tableName}][field]`);
            url.searchParams.delete(`sort[${tableName}][direction]`);
        }

        window.location.href = url.toString();
    }

    /**
     * Update only the sort direction for a table (used when no select element exists)
     * @param {string} tableName - The table name
     * @param {string} newDirection - The new sort direction ('asc' or 'desc')
     */
    updateSortDirection(tableName, newDirection) {
        if (!tableName) {
            console.error('[GridView] No table name for direction update');
            return;
        }

        const url = new URL(window.location.href);
        
        // Update only the direction, preserve existing field if any
        url.searchParams.set(`sort[${tableName}][direction]`, newDirection);

        window.location.href = url.toString();
    }

    /**
     * Initialize scroll shadow indicators for compact view tables
     * 
     * Adds/removes CSS classes based on scroll position to show shadows
     * on the edges of fixed columns when content is scrolled.
     */
    initializeScrollShadows() {
        const scrollContainers = document.querySelectorAll('.compactview-table-wrapper');
        
        if (scrollContainers.length === 0) {
            return;
        }
        
        scrollContainers.forEach(container => {
            // Update shadow state on scroll
            const updateScrollState = () => {
                const scrollLeft = container.scrollLeft;
                const scrollWidth = container.scrollWidth;
                const clientWidth = container.clientWidth;
                const maxScroll = scrollWidth - clientWidth;
                
                // Has been scrolled from left
                if (scrollLeft > 2) {
                    container.classList.add('is-scrolled');
                } else {
                    container.classList.remove('is-scrolled');
                }
                
                // Has more content on the right
                if (scrollLeft < maxScroll - 2) {
                    container.classList.add('has-right-scroll');
                } else {
                    container.classList.remove('has-right-scroll');
                }
            };
            
            // Initial state check
            updateScrollState();
            
            // Listen for scroll events
            container.addEventListener('scroll', updateScrollState, { passive: true });
            
            // Also update on window resize
            window.addEventListener('resize', updateScrollState, { passive: true });
        });
    }

    // =========================================================================
    // Pagination Page Input
    // =========================================================================

    /**
     * Initialize pagination page number inputs.
     * Navigates to the entered page on Enter or blur.
     */
    initializePaginationInputs() {
        const inputs = document.querySelectorAll('[data-pagination-input]');

        if (inputs.length === 0) {
            return;
        }

        inputs.forEach(input => {
            const originalValue = input.value;

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.navigateToPage(input);
                }
            });

            input.addEventListener('blur', () => {
                if (input.value !== originalValue) {
                    this.navigateToPage(input);
                }
            });
        });
    }

    /**
     * Navigate to the page number entered in the pagination input.
     * @param {HTMLInputElement} input - The pagination input element
     */
    navigateToPage(input) {
        const page = parseInt(input.value, 10);
        const min = parseInt(input.min, 10) || 1;
        const max = parseInt(input.max, 10) || 1;

        if (isNaN(page) || page < min || page > max) {
            // Reset to current value on invalid input
            input.value = input.defaultValue;
            return;
        }

        const baseUrl = input.dataset.paginationUrl;
        const table = input.dataset.paginationTable;

        if (!baseUrl || !table) {
            return;
        }

        window.location.href = baseUrl + '&pointer[' + encodeURIComponent(table) + ']=' + page;
    }

    // =========================================================================
    // Search
    // =========================================================================

    /**
     * Initialize search input handlers
     */
    initializeSearch() {
        const searchInputs = document.querySelectorAll('[data-gridview-search]');
        
        if (searchInputs.length === 0) {
            return;
        }
        
        searchInputs.forEach(input => {
            // Submit on Enter key
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.submitSearch(input);
                }
            });
            
            // Also submit on blur if value changed
            let originalValue = input.value;
            input.addEventListener('blur', () => {
                if (input.value !== originalValue) {
                    setTimeout(() => {
                        if (document.activeElement !== input) {
                            this.submitSearch(input);
                        }
                    }, 100);
                }
            });
            
            // Submit when clicking native clear button
            input.addEventListener('search', () => {
                if (input.value === '') {
                    this.submitSearch(input);
                }
            });
        });
    }
    
    /**
     * Submit search - navigate to URL with searchTerm parameter
     */
    submitSearch(input) {
        const searchTerm = input.value.trim();
        const url = new URL(window.location.href);
        
        if (searchTerm) {
            url.searchParams.set('searchTerm', searchTerm);
        } else {
            url.searchParams.delete('searchTerm');
        }
        
        window.location.href = url.toString();
    }

    // =========================================================================
    // Compact View Dropdowns - Teleport to body
    // =========================================================================

    /**
     * Teleport compact view dropdown menus to <body> when opened.
     *
     * Sticky columns + overflow-x:auto on the table wrapper create a stacking
     * context that clips absolutely-positioned dropdown menus. By moving the
     * menu to <body> and using position:fixed we escape all overflow and
     * z-index constraints. The menu is returned to its original parent on close.
     */
    initializeCompactDropdowns() {
        document.querySelectorAll('[data-cv-dropdown]').forEach(dropdown => {
            const toggle = dropdown.querySelector('[data-bs-toggle="dropdown"]');
            const menu = dropdown.querySelector('.dropdown-menu');
            if (!toggle || !menu) return;

            // On show: teleport menu to body and position it
            toggle.addEventListener('show.bs.dropdown', () => {
                // Store original parent so we can return the menu later
                menu._cvOriginalParent = dropdown;

                // Get toggle button position
                const rect = toggle.getBoundingClientRect();

                // Move menu to body
                document.body.appendChild(menu);
                menu.classList.add('cv-dropdown-teleported');

                // Position: align right edge with toggle, below it
                const menuWidth = menu.offsetWidth || 160;
                let top = rect.bottom + 2;
                let left = rect.right - menuWidth;

                // If menu would overflow bottom of viewport, open upward
                const menuHeight = menu.offsetHeight || 200;
                if (top + menuHeight > window.innerHeight) {
                    top = rect.top - menuHeight - 2;
                }

                // Keep within viewport
                if (left < 4) left = 4;
                if (top < 4) top = 4;

                menu.style.top = top + 'px';
                menu.style.left = left + 'px';
            });

            // On hidden: return menu to original parent
            toggle.addEventListener('hidden.bs.dropdown', () => {
                menu.classList.remove('cv-dropdown-teleported');
                menu.style.top = '';
                menu.style.left = '';
                if (menu._cvOriginalParent) {
                    menu._cvOriginalParent.appendChild(menu);
                    delete menu._cvOriginalParent;
                }
            });
        });
    }

    // =========================================================================
    // Multi Record Selection: Clipboard Actions
    // =========================================================================

    /**
     * Handle "Transfer to clipboard" and "Remove from clipboard" buttons.
     * Direct click handlers since TYPO3's multi-record-selection events
     * may not fire for custom action names.
     */
    initializeClipboardSelectionActions() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-multi-record-selection-action="copyMarked"], [data-multi-record-selection-action="removeMarked"]');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            const action = btn.dataset.multiRecordSelectionAction;
            const mode = action === 'copyMarked' ? 'copy' : 'remove';

            // Parse config from data attribute
            let config = {};
            try {
                config = JSON.parse(btn.dataset.multiRecordSelectionActionConfig || '{}');
            } catch (err) {
                // ignore parse errors
            }

            const tableName = config.tableName || '';
            if (!tableName) return;

            // Find the selection container
            const container = btn.closest('[data-multi-record-selection-identifier]');
            const scope = container || document;

            // Get all checked checkboxes
            const checked = scope.querySelectorAll('.t3js-multi-record-selection-check:checked');
            if (checked.length === 0) {
                this.showNotification('No records selected', 'Please select records first', 'warning');
                return;
            }

            // Collect UIDs
            const uids = [];
            checked.forEach(cb => {
                const el = cb.closest('[data-uid]');
                if (el?.dataset?.uid) {
                    uids.push(el.dataset.uid);
                }
            });

            if (uids.length === 0) return;

            this.processClipboardBulk(tableName, uids, mode);
        });
    }

    /**
     * Send bulk clipboard operation via AJAX.
     */
    async processClipboardBulk(tableName, uids, mode) {
        const url = TYPO3?.settings?.ajaxUrls?.clipboard_process;
        if (!url) return;

        const fullUrl = new URL(url, window.location.origin);

        if (mode === 'copy') {
            fullUrl.searchParams.set('CB[setCopyMode]', '1');
        }

        uids.forEach(uid => {
            fullUrl.searchParams.set(
                `CB[el][${tableName}|${uid}]`,
                mode === 'remove' ? '0' : '1'
            );
        });

        try {
            await fetch(fullUrl.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            this.showNotification(
                mode === 'copy' ? 'Transferred to clipboard' : 'Removed from clipboard',
                `${uids.length} record(s)`,
                'success'
            );

            // Update clipboard panel and reload
            const clipboardPanel = document.querySelector('typo3-backend-clipboard-panel');
            if (clipboardPanel) {
                clipboardPanel.dispatchEvent(new Event('typo3:clipboard:update'));
            }

            window.location.reload();
        } catch (err) {
            console.error('[GridView] Clipboard error:', err);
            this.showNotification('Clipboard action failed', err.message, 'error');
        }
    }
}

// Auto-init
const gv = new GridViewActions();
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => gv.init());
} else {
    gv.init();
}

export default gv;
