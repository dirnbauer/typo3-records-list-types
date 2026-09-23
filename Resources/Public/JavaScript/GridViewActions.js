import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Icons from '@typo3/backend/icons.js';
import {sudoModeInterceptor} from '@typo3/backend/security/sudo-mode-interceptor.js';

/**
 * Modules needed only for some actions load on demand, so the element works
 * even before, or without, the modules behind them.
 */
const load = async (name) => (await import(name)).default;

/**
 * <records-list-types-actions> wraps one alternative view of the Records
 * module and adds what Core's own scripts do not cover there:
 *
 * - reordering cards by drag and drop, and from the keyboard on the card's
 *   handle (Space or Enter grabs, arrow keys move, Space or Enter drops,
 *   Escape cancels); Core's "Move up/down" buttons are the pointer
 *   alternative without dragging
 * - the visibility button of Core's controls on cards: Core updates table
 *   rows only
 * - the page number input of the pagination
 * - the legacy data-gridview-action buttons of custom templates
 *
 * Every listener is bound to the element itself, so several views on one
 * page (records and page translations) never handle an event twice.
 */
class RecordsListTypesActions extends HTMLElement {
    connectedCallback() {
        if (this.liveRegion) {
            return;
        }

        this.liveRegion = document.createElement('div');
        this.liveRegion.className = 'visually-hidden';
        this.liveRegion.setAttribute('role', 'status');
        this.liveRegion.setAttribute('aria-live', 'polite');
        this.append(this.liveRegion);

        this.drag = null;
        this.grab = null;

        this.addEventListener('click', (event) => this.onClick(event));
        this.addEventListener('keydown', (event) => this.onKeydown(event));
        this.addEventListener('focusout', (event) => this.onFocusout(event));
        this.addEventListener('dragstart', (event) => this.onDragStart(event));
        this.addEventListener('dragover', (event) => this.onDragOver(event));
        this.addEventListener('drop', (event) => this.onDrop(event));
        this.addEventListener('dragend', () => this.endDrag());
    }

    // -------------------------------------------------------------------------
    // Labels and announcements
    // -------------------------------------------------------------------------

    /**
     * A label exported by the controller to TYPO3.lang, with ICU-style {name}
     * placeholders replaced.
     */
    lang(key, fallback, placeholders = {}) {
        const registered = window.TYPO3?.lang?.[key];
        const label = typeof registered === 'string' && registered !== '' ? registered : fallback;
        return label.replace(/\{(\w+)\}/g, (match, name) => (
            Object.hasOwn(placeholders, name) ? String(placeholders[name]) : match
        ));
    }

    announce(message) {
        this.liveRegion.textContent = '';
        window.setTimeout(() => {
            this.liveRegion.textContent = message;
        }, 50);
    }

    recordTitle(element) {
        const record = element?.closest('[data-record-title]');
        const title = record?.dataset.recordTitle?.trim();
        return title || this.lang('labels.no_title', 'No title');
    }

    // -------------------------------------------------------------------------
    // Clicks
    // -------------------------------------------------------------------------

    onClick(event) {
        const visibilityButton = event.target.closest('button[data-datahandler-action="visibility"]');
        if (visibilityButton && this.contains(visibilityButton) && !visibilityButton.closest('tr')) {
            // Core's recordlist.js handles table rows; cards are ours.
            event.preventDefault();
            event.stopPropagation();
            this.toggleVisibility(visibilityButton);
            return;
        }

        const legacyButton = event.target.closest('[data-gridview-action]');
        if (legacyButton && this.contains(legacyButton)) {
            event.preventDefault();
            this.runLegacyAction(legacyButton);
        }
    }

    async toggleVisibility(button) {
        const record = button.closest('[data-table][data-uid]');
        const table = record?.dataset.table;
        const uid = Number.parseInt(record?.dataset.uid ?? '', 10);
        const url = window.TYPO3?.settings?.ajaxUrls?.record_toggle_visibility;
        if (!table || !uid || !url) {
            return;
        }

        button.disabled = true;
        const action = button.dataset.datahandlerStatus === 'visible' ? 'hide' : 'show';
        try {
            const response = await new AjaxRequest(url)
                .addMiddleware(sudoModeInterceptor)
                .post({table, uid, action});
            const data = await response.resolve();
            const hidden = !data.isVisible;

            button.dataset.datahandlerStatus = hidden ? 'hidden' : 'visible';
            button.title = hidden ? button.dataset.datahandlerHiddenLabel : button.dataset.datahandlerVisibleLabel;
            await this.replaceIcon(button.querySelector('.t3js-icon'), hidden ? 'actions-edit-unhide' : 'actions-edit-hide');
            this.replaceRecordIcon(record, data.icon);
            this.updateHiddenState(record, hidden);
            if (table === 'pages') {
                top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
            }

            const title = this.recordTitle(record);
            this.announce(hidden
                ? this.lang('a11y.recordHidden', '{title} is now hidden', {title})
                : this.lang('a11y.recordVisible', '{title} is now visible', {title}));
        } catch (error) {
            await this.notifyError(this.lang('notification.updateFailed', 'Update failed'), error);
        } finally {
            button.disabled = false;
        }
    }

    async replaceIcon(icon, identifier) {
        if (!icon) {
            return;
        }
        const markup = await Icons.getIcon(identifier, Icons.sizes.small);
        icon.replaceWith(document.createRange().createContextualFragment(markup));
    }

    replaceRecordIcon(record, markup) {
        const icon = record.querySelector('.rlt-record-icon .t3js-icon');
        if (icon && typeof markup === 'string' && markup !== '') {
            icon.replaceWith(document.createRange().createContextualFragment(markup));
        }
    }

    updateHiddenState(record, hidden) {
        for (const name of ['rlt-card', 'rlt-teaser']) {
            if (record.classList.contains(name)) {
                record.classList.toggle(`${name}--hidden`, hidden);
            }
        }

        const badges = record.querySelector('.rlt-badges');
        const badge = badges?.querySelector('[data-rlt-hidden-badge]');
        if (hidden && badges && !badge) {
            const newBadge = document.createElement('span');
            newBadge.className = 'badge badge-warning';
            newBadge.dataset.rltHiddenBadge = '';
            newBadge.textContent = this.lang('state.hidden', 'Hidden');
            badges.prepend(newBadge);
        } else if (!hidden) {
            badge?.remove();
        }
    }

    /**
     * Buttons of templates written for records_list_types 1.x.
     */
    async runLegacyAction(button) {
        const table = button.dataset.table;
        const uid = button.dataset.uid;
        const action = button.dataset.gridviewAction;
        const ContextMenuActions = ['delete', 'copy', 'cut'].includes(action)
            ? await load('@typo3/backend/context-menu-actions.js')
            : null;
        switch (action) {
            case 'hide':
            case 'show':
                this.toggleVisibilityLegacy(table, uid, action, button);
                break;
            case 'delete':
                ContextMenuActions.deleteRecord(table, uid, {
                    title: this.lang('action.delete', 'Delete record'),
                    message: this.lang('action.delete.confirm', 'Are you sure you want to delete “{title}”?', {title: this.recordTitle(button)}),
                    buttonCloseText: this.lang('action.cancel', 'Cancel'),
                    buttonOkText: this.lang('action.delete.confirmButton', 'Delete'),
                });
                break;
            case 'copy':
                ContextMenuActions.copy(table, uid);
                break;
            case 'cut':
                ContextMenuActions.cut(table, uid);
                break;
            case 'info':
                top.TYPO3?.InfoWindow?.showItem(table, uid);
                break;
            case 'history': {
                const moduleUrl = top.TYPO3?.settings?.RecordHistory?.moduleUrl;
                if (moduleUrl) {
                    const url = new URL(moduleUrl, window.location.origin);
                    url.searchParams.set('element', `${table}:${uid}`);
                    url.searchParams.set('returnUrl', window.location.pathname + window.location.search);
                    window.location.href = url.toString();
                }
                break;
            }
        }
    }

    async toggleVisibilityLegacy(table, uid, action, button) {
        const url = window.TYPO3?.settings?.ajaxUrls?.record_toggle_visibility;
        if (!url || !table || !uid) {
            return;
        }
        button.disabled = true;
        try {
            const response = await new AjaxRequest(url)
                .addMiddleware(sudoModeInterceptor)
                .post({table, uid: Number.parseInt(uid, 10), action});
            await response.resolve();
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            await this.notifyError(this.lang('notification.updateFailed', 'Update failed'), error);
        }
    }

    async notifyError(title, error) {
        const Notification = await load('@typo3/backend/notification.js');
        let messages = [];
        if (typeof error?.resolve === 'function') {
            try {
                messages = (await error.resolve())?.messages ?? [];
            } catch {
                messages = [];
            }
        }
        if (messages.length === 0) {
            Notification.error(title, error?.message || this.lang('notification.requestFailed', 'Request failed'));
            return;
        }
        for (const message of messages) {
            Notification.error(message.title || title, message.message || this.lang('notification.unknownError', 'Unknown error'));
        }
    }

    // -------------------------------------------------------------------------
    // Keyboard: pagination input and reordering
    // -------------------------------------------------------------------------

    onKeydown(event) {
        const pageInput = event.target.closest('[data-pagination-input]');
        if (pageInput && event.key === 'Enter') {
            event.preventDefault();
            this.goToPage(pageInput);
            return;
        }

        if (this.grab) {
            this.onGrabKeydown(event);
            return;
        }

        const handle = event.target.closest('[data-rlt-drag-handle]');
        if (handle && (event.key === ' ' || event.key === 'Enter')) {
            event.preventDefault();
            this.startGrab(handle);
        }
    }

    onFocusout(event) {
        if (this.grab && !this.grab.item.contains(event.relatedTarget)) {
            this.cancelGrab(false);
        }
    }

    goToPage(input) {
        const page = Number.parseInt(input.value, 10);
        const max = Number.parseInt(input.max, 10) || 1;
        if (Number.isNaN(page) || page < 1 || page > max) {
            input.value = input.defaultValue;
            return;
        }
        const url = new URL(input.dataset.paginationUrl, window.location.origin);
        url.searchParams.set(`pointer[${input.dataset.paginationTable}]`, String(page));
        window.location.href = url.toString();
    }

    startGrab(handle) {
        const item = handle.closest('.rlt-cards-item');
        const list = item?.closest('.rlt-cards');
        if (!item || !list) {
            return;
        }
        const items = this.compatibleItems(list, item);
        this.grab = {handle, item, list, items, from: items.indexOf(item), to: items.indexOf(item)};
        item.classList.add('rlt-is-grabbed');
        this.announce(this.lang('drag.grabbed', '{title} grabbed. Use the arrow keys to move it.', {title: this.recordTitle(handle)}));
    }

    onGrabKeydown(event) {
        const {items} = this.grab;
        let target = this.grab.to;
        switch (event.key) {
            case 'ArrowUp':
            case 'ArrowLeft':
                target = Math.max(0, target - 1);
                break;
            case 'ArrowDown':
            case 'ArrowRight':
                target = Math.min(items.length - 1, target + 1);
                break;
            case 'Home':
                target = 0;
                break;
            case 'End':
                target = items.length - 1;
                break;
            case 'Escape':
                event.preventDefault();
                this.cancelGrab(true);
                return;
            case ' ':
            case 'Enter':
                event.preventDefault();
                this.dropGrab();
                return;
            default:
                return;
        }
        event.preventDefault();
        if (target === this.grab.to) {
            return;
        }
        this.grab.to = target;
        this.clearDropIndicators();
        if (target !== this.grab.from) {
            items[target].classList.add(target < this.grab.from ? 'rlt-drop-before' : 'rlt-drop-after');
        }
        this.announce(this.lang('drag.position', 'Position {position} of {total}', {position: target + 1, total: items.length}));
    }

    dropGrab() {
        const {item, list, items, from, to} = this.grab;
        this.releaseGrab();
        if (to === from) {
            this.announce(this.lang('drag.cancelled', 'Reordering cancelled'));
            return;
        }
        const targetItem = items[to];
        this.announce(this.lang('drag.moved', 'Record moved to position {position}', {position: to + 1}));
        this.executeMove(item, this.moveTarget(list, item, targetItem, to < from ? 'before' : 'after'));
    }

    cancelGrab(announce) {
        const handle = this.grab.handle;
        this.releaseGrab();
        if (announce) {
            this.announce(this.lang('drag.cancelled', 'Reordering cancelled'));
            handle.focus();
        }
    }

    releaseGrab() {
        this.grab.item.classList.remove('rlt-is-grabbed');
        this.clearDropIndicators();
        this.grab = null;
    }

    // -------------------------------------------------------------------------
    // Pointer: drag and drop
    // -------------------------------------------------------------------------

    onDragStart(event) {
        const card = event.target.closest?.('.rlt-card[draggable="true"]');
        const item = card?.closest('.rlt-cards-item');
        const list = item?.closest('.rlt-cards[data-can-reorder="1"]');
        if (!item || !list) {
            return;
        }
        this.drag = {item, list, items: this.compatibleItems(list, item), target: null, position: null};
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.uid ?? '');
        window.setTimeout(() => {
            item.classList.add('rlt-is-dragging');
            this.dropzone(list)?.removeAttribute('hidden');
        }, 0);
    }

    onDragOver(event) {
        if (!this.drag) {
            return;
        }
        const dropzone = event.target.closest?.('[data-rlt-dropzone]');
        if (dropzone && dropzone === this.dropzone(this.drag.list)) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.clearDropIndicators();
            dropzone.classList.add('rlt-drop-active');
            this.drag.target = null;
            this.drag.position = 'end';
            return;
        }

        const target = this.dropTarget(event.clientX, event.clientY);
        if (!target) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        if (target.item !== this.drag.target || target.position !== this.drag.position) {
            this.clearDropIndicators();
            target.item.classList.add(`rlt-drop-${target.position}`);
            this.drag.target = target.item;
            this.drag.position = target.position;
        }
    }

    onDrop(event) {
        if (!this.drag) {
            return;
        }
        event.preventDefault();
        const {item, list, target, position} = this.drag;
        this.endDrag();
        if (position === 'end') {
            const items = this.compatibleItems(list, item);
            const last = items[items.length - 1];
            if (last && last !== item) {
                this.executeMove(item, this.moveTarget(list, item, last, 'after'));
            }
            return;
        }
        if (target && target !== item) {
            this.executeMove(item, this.moveTarget(list, item, target, position));
        }
    }

    endDrag() {
        if (!this.drag) {
            return;
        }
        this.drag.item.classList.remove('rlt-is-dragging');
        const dropzone = this.dropzone(this.drag.list);
        dropzone?.setAttribute('hidden', '');
        dropzone?.classList.remove('rlt-drop-active');
        this.clearDropIndicators();
        this.drag = null;
    }

    /**
     * The card under the pointer, or the closest one in the row the pointer is
     * in, so gaps between cards and the end of a row are valid targets too.
     */
    dropTarget(x, y) {
        const entries = this.drag.items
            .filter((item) => item !== this.drag.item)
            .map((item) => ({item, rect: item.getBoundingClientRect()}))
            .filter(({rect}) => rect.width > 0);
        if (entries.length === 0) {
            return null;
        }
        const rtl = window.getComputedStyle(this.drag.list).direction === 'rtl';
        const rowOf = (rect) => Math.round(rect.top);
        const nearestRowTop = entries
            .map(({rect}) => ({top: rowOf(rect), distance: y < rect.top ? rect.top - y : Math.max(0, y - rect.bottom)}))
            .sort((a, b) => a.distance - b.distance)[0].top;
        const row = entries
            .filter(({rect}) => Math.abs(rowOf(rect) - nearestRowTop) <= 8)
            .sort((a, b) => (rtl ? b.rect.left - a.rect.left : a.rect.left - b.rect.left));
        for (const entry of row) {
            const middle = entry.rect.left + entry.rect.width / 2;
            if (rtl ? x > middle : x < middle) {
                return {item: entry.item, position: 'before'};
            }
        }
        return {item: row[row.length - 1].item, position: 'after'};
    }

    /**
     * DataHandler's move target: "-uid" puts a record after that record, a
     * page id puts it first on the page. In descending order the visible
     * order is the reverse of the sorting field.
     */
    moveTarget(list, item, targetItem, position) {
        const items = this.compatibleItems(list, item).filter((candidate) => candidate !== item);
        const index = items.indexOf(targetItem);
        const descending = list.dataset.sortDirection === 'desc';
        const after = descending ? position === 'before' : position === 'after';
        if (after) {
            return `-${targetItem.dataset.uid}`;
        }
        const neighbour = descending ? items[index + 1] : items[index - 1];
        if (neighbour) {
            return `-${neighbour.dataset.uid}`;
        }
        return item.querySelector('.rlt-card')?.dataset.pid || list.dataset.pageId;
    }

    /**
     * Cards the dragged card may be dropped between: same table and, for
     * content elements, the same column (DataHandler rejects other moves).
     */
    compatibleItems(list, item) {
        const group = item.querySelector('.rlt-card')?.dataset.reorderGroup ?? '';
        return Array.from(list.querySelectorAll(':scope > .rlt-cards-item'))
            .filter((candidate) => (candidate.querySelector('.rlt-card')?.dataset.reorderGroup ?? '') === group);
    }

    dropzone(list) {
        const sibling = list.nextElementSibling;
        return sibling?.matches('[data-rlt-dropzone]') ? sibling : null;
    }

    clearDropIndicators() {
        this.querySelectorAll('.rlt-drop-before, .rlt-drop-after').forEach((element) => {
            element.classList.remove('rlt-drop-before', 'rlt-drop-after');
        });
    }

    async executeMove(item, target) {
        const card = item.querySelector('.rlt-card');
        const table = card?.dataset.table;
        const uid = card?.dataset.uid;
        if (!table || !uid || !target) {
            return;
        }
        item.classList.add('rlt-is-dragging');
        try {
            const AjaxDataHandler = await load('@typo3/backend/ajax-data-handler.js');
            const data = await AjaxDataHandler.process({cmd: {[table]: {[uid]: {move: String(target)}}}});
            if (data?.hasErrors) {
                item.classList.remove('rlt-is-dragging');
                return;
            }
            if (table === 'pages') {
                top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
            }
            window.location.reload();
        } catch (error) {
            item.classList.remove('rlt-is-dragging');
            await this.notifyError(this.lang('notification.moveFailed', 'Move failed'), error);
        }
    }
}

if (!customElements.get('records-list-types-actions')) {
    customElements.define('records-list-types-actions', RecordsListTypesActions);
}

export default RecordsListTypesActions;
