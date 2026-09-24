class RecordFilters {
    init() {
        document.querySelectorAll('[data-recordlist-category-filter]').forEach((filter) => {
            this.initializeCategoryFilter(filter);
        });
    }

    initializeCategoryFilter(filter) {
        const toggle = filter.querySelector('[data-recordlist-category-filter-toggle]');
        const menu = filter.querySelector('[data-recordlist-category-filter-menu]');
        if (!(toggle instanceof HTMLButtonElement) || !(menu instanceof HTMLElement)) {
            return;
        }

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            this.toggleMenu(filter, toggle, menu);
        });

        filter.querySelectorAll('[data-recordlist-category-filter-input]').forEach((input) => {
            input.addEventListener('change', () => {
                const option = input.closest('[data-recordlist-category-filter-option]');
                if (option instanceof HTMLElement) {
                    this.updateSelectedLabel(filter, toggle, option);
                }
                this.closeMenu(toggle, menu);
            });
        });

        document.addEventListener('click', (event) => {
            if (event.target instanceof Node && !filter.contains(event.target)) {
                this.closeMenu(toggle, menu);
            }
        });

        filter.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closeMenu(toggle, menu);
                toggle.focus();
            }
        });
    }

    toggleMenu(filter, toggle, menu) {
        document.querySelectorAll('[data-recordlist-category-filter]').forEach((otherFilter) => {
            if (otherFilter === filter) {
                return;
            }
            const otherToggle = otherFilter.querySelector('[data-recordlist-category-filter-toggle]');
            const otherMenu = otherFilter.querySelector('[data-recordlist-category-filter-menu]');
            if (otherToggle instanceof HTMLButtonElement && otherMenu instanceof HTMLElement) {
                this.closeMenu(otherToggle, otherMenu);
            }
        });

        if (menu.hidden) {
            menu.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            return;
        }

        this.closeMenu(toggle, menu);
    }

    closeMenu(toggle, menu) {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    updateSelectedLabel(filter, toggle, option) {
        const selectedText = toggle.querySelector('[data-recordlist-category-filter-primary]');
        const selectedTranslations = toggle.querySelector('[data-recordlist-category-filter-translations]');
        const optionPrimary = option.querySelector('.recordlist-category-filter__primary');
        const optionTranslations = option.querySelector('.recordlist-category-filter__translations');

        if (selectedText instanceof HTMLElement && optionPrimary instanceof HTMLElement) {
            selectedText.textContent = optionPrimary.textContent;
        }
        if (optionTranslations instanceof HTMLElement) {
            if (selectedTranslations instanceof HTMLElement) {
                selectedTranslations.textContent = optionTranslations.textContent;
            } else {
                const translations = document.createElement('span');
                translations.className = 'recordlist-category-filter__translations';
                translations.setAttribute('data-recordlist-category-filter-translations', '');
                translations.textContent = optionTranslations.textContent;
                toggle.querySelector('.recordlist-category-filter__text')?.appendChild(translations);
            }
        } else if (selectedTranslations instanceof HTMLElement) {
            selectedTranslations.remove();
        }

        toggle.setAttribute('title', option.getAttribute('title') || toggle.textContent?.trim() || '');
    }
}

const recordFilters = new RecordFilters();
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => recordFilters.init());
} else {
    recordFilters.init();
}

export default recordFilters;
