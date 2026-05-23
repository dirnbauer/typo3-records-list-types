const EMBEDDED_FRAME_PARAM = 'recordsListTypesHistoryFrame';

class HistoryOverlay {
    constructor() {
        this.initialize = this.initialize.bind(this);
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', this.initialize, {once: true});
            return;
        }
        this.initialize();
    }

    initialize() {
        const module = document.querySelector('.module');
        if (!module) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const isEmbeddedPageHistory = params.get(EMBEDDED_FRAME_PARAM) === '1';

        document.documentElement.classList.add('records-list-types-history-document');
        document.body.classList.add('records-list-types-history-body');
        module.classList.add('records-list-types-history-view');
        module.classList.toggle('records-list-types-history-view--embedded', isEmbeddedPageHistory);

        this.expandParentModal();
        this.preserveEmbeddedParameter(isEmbeddedPageHistory);

        if (isEmbeddedPageHistory) {
            return;
        }

        this.createTabs(module);
    }

    expandParentModal() {
        try {
            const frame = window.frameElement;
            if (!frame) {
                return;
            }

            const modal = frame.closest('typo3-backend-modal');
            const dialog = modal?.querySelector('dialog.t3js-modal');
            const modalBody = dialog?.querySelector('.t3js-modal-body');
            const iframe = dialog?.querySelector('.t3js-modal-iframe');

            modal?.classList.add('records-list-types-history-modal-host');
            dialog?.classList.add('records-list-types-history-modal');

            if (dialog) {
                dialog.style.setProperty('--typo3-modal-width', 'min(1540px, calc(100dvw - 2rem))');
                dialog.style.setProperty('--typo3-modal-height', 'calc(100dvh - 2rem)');
                dialog.style.setProperty('--typo3-modal-offset', '1rem');
            }

            if (modalBody) {
                modalBody.style.padding = '0';
                modalBody.style.overflow = 'hidden';
            }

            if (iframe) {
                iframe.style.height = '100%';
                iframe.style.width = '100%';
            }
        } catch {
            // The history route also works outside a modal.
        }
    }

    preserveEmbeddedParameter(isEmbeddedPageHistory) {
        if (!isEmbeddedPageHistory) {
            return;
        }

        document.querySelectorAll('a[href], form[action]').forEach((element) => {
            const attributeName = element instanceof HTMLFormElement ? 'action' : 'href';
            const value = element.getAttribute(attributeName);
            if (!value) {
                return;
            }

            const url = this.createUrl(value);
            if (!url || !this.isHistoryUrl(url)) {
                return;
            }

            url.searchParams.set(EMBEDDED_FRAME_PARAM, '1');
            element.setAttribute(attributeName, url.toString());
        });
    }

    createTabs(module) {
        const container = module.querySelector('.module-body-container');
        if (!container || container.dataset.historyTabsInitialized === '1') {
            return;
        }

        container.dataset.historyTabsInitialized = '1';

        const pageHistoryUrl = this.findPageHistoryUrl();
        const currentNodes = Array.from(container.childNodes);
        const elementPanel = document.createElement('section');
        elementPanel.id = 'records-list-types-history-panel-element';
        elementPanel.className = 'records-list-types-history-panel records-list-types-history-panel--element';
        elementPanel.setAttribute('role', 'tabpanel');
        elementPanel.setAttribute('aria-labelledby', 'records-list-types-history-tab-element');

        currentNodes.forEach((node) => elementPanel.appendChild(node));

        const tabList = document.createElement('div');
        tabList.className = 'records-list-types-history-tabs';
        tabList.setAttribute('role', 'tablist');

        const elementTab = this.createTab(
            'records-list-types-history-tab-element',
            elementPanel.id,
            this.label('historyOverlay.elementTab', 'Element history'),
            true,
        );
        tabList.appendChild(elementTab);

        let pagePanel = null;
        if (pageHistoryUrl) {
            pagePanel = this.createPageHistoryPanel(pageHistoryUrl);
            const pageTab = this.createTab(
                'records-list-types-history-tab-page',
                pagePanel.id,
                this.label('historyOverlay.pageTab', 'Page and content history'),
                false,
            );
            tabList.appendChild(pageTab);
        }

        container.replaceChildren(tabList, elementPanel);
        if (pagePanel) {
            container.appendChild(pagePanel);
        }

        tabList.addEventListener('click', (event) => {
            const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
            if (!(tab instanceof HTMLButtonElement)) {
                return;
            }

            this.activateTab(container, tab);
        });

        tabList.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            const tabs = Array.from(tabList.querySelectorAll('[role="tab"]'));
            const currentIndex = tabs.indexOf(document.activeElement);
            if (currentIndex === -1) {
                return;
            }

            event.preventDefault();
            const nextIndex = this.getNextTabIndex(event.key, currentIndex, tabs.length);
            const nextTab = tabs[nextIndex];
            nextTab.focus();
            this.activateTab(container, nextTab);
        });
    }

    createTab(id, panelId, label, active) {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.id = id;
        tab.className = 'records-list-types-history-tab';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panelId);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        tab.textContent = label;

        return tab;
    }

    createPageHistoryPanel(pageHistoryUrl) {
        const panel = document.createElement('section');
        panel.id = 'records-list-types-history-panel-page';
        panel.className = 'records-list-types-history-panel records-list-types-history-panel--page';
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', 'records-list-types-history-tab-page');
        panel.hidden = true;

        const iframe = document.createElement('iframe');
        iframe.className = 'records-list-types-history-page-frame';
        iframe.src = this.createEmbeddedHistoryUrl(pageHistoryUrl);
        iframe.title = this.label('historyOverlay.pageFrameTitle', 'Page and content history');

        panel.appendChild(iframe);

        return panel;
    }

    activateTab(container, activeTab) {
        const tabs = Array.from(container.querySelectorAll('.records-list-types-history-tab'));
        tabs.forEach((tab) => {
            const active = tab === activeTab;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });

        container.querySelectorAll('.records-list-types-history-panel').forEach((panel) => {
            panel.hidden = panel.id !== activeTab.getAttribute('aria-controls');
        });
    }

    getNextTabIndex(key, currentIndex, totalTabs) {
        if (key === 'Home') {
            return 0;
        }
        if (key === 'End') {
            return totalTabs - 1;
        }
        if (key === 'ArrowLeft') {
            return (currentIndex - 1 + totalTabs) % totalTabs;
        }
        return (currentIndex + 1) % totalTabs;
    }

    findPageHistoryUrl() {
        const currentElement = new URLSearchParams(window.location.search).get('element') || '';
        if (currentElement.startsWith('pages:')) {
            return null;
        }

        const links = Array.from(document.querySelectorAll('a[href]'));
        for (const link of links) {
            const url = this.createUrl(link.href);
            if (!url || !this.isHistoryUrl(url)) {
                continue;
            }

            const element = url.searchParams.get('element') || '';
            if (element.startsWith('pages:')) {
                return url;
            }
        }

        return null;
    }

    createEmbeddedHistoryUrl(pageHistoryUrl) {
        const url = pageHistoryUrl instanceof URL ? new URL(pageHistoryUrl.toString()) : this.createUrl(pageHistoryUrl);
        if (!url) {
            return '';
        }

        url.searchParams.set(EMBEDDED_FRAME_PARAM, '1');
        url.hash = '';

        return url.toString();
    }

    isHistoryUrl(url) {
        return url.pathname.includes('record/history') || url.searchParams.has('element');
    }

    createUrl(value) {
        try {
            return new URL(value, window.location.href);
        } catch {
            return null;
        }
    }

    label(key, fallback) {
        try {
            return window.TYPO3?.lang?.[key] || parent?.TYPO3?.lang?.[key] || top?.TYPO3?.lang?.[key] || fallback;
        } catch {
            return fallback;
        }
    }
}

new HistoryOverlay();
