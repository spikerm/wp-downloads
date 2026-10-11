(function () {
    'use strict';
    function init(root) {
        const groups = Array.from(root.querySelectorAll('.rex-file-group'));
        const folders = Array.from(root.querySelectorAll('.rex-folder'));
        const search = root.querySelector('.rex-search');
        if (!search) return;
        const category = root.querySelector('.rex-filter-category');
        const type = root.querySelector('.rex-filter-type');
        const favorite = root.querySelector('.rex-filter-favorites');
        const status = root.querySelector('.rex-filter-status');
        const openState = new Map(folders.map(folder => [folder, folder.open]));
        let wasFiltered = false;
        Array.from(new Set(groups.map(group => group.dataset.type).filter(Boolean))).sort().forEach(ext => {
            const option = document.createElement('option'); option.value = ext; option.textContent = ext.toUpperCase(); type.appendChild(option);
        });
        function filter() {
            const query = search.value.trim().toLocaleLowerCase();
            const selected = category.value ? folders.find(folder => folder.dataset.category === category.value) : null;
            const filtering = !!(query || category.value || type.value || favorite.checked);
            const visible = new Set();
            groups.forEach(group => {
                const matches = (!query || group.dataset.search.toLocaleLowerCase().includes(query)) && (!category.value || (selected && selected.contains(group))) && (!type.value || group.dataset.type === type.value) && (!favorite.checked || group.dataset.favorite === '1');
                group.hidden = !matches;
                if (matches) visible.add(group.dataset.id);
            });
            folders.forEach(folder => {
                if (filtering) {
                    const hasFiles = Array.from(folder.querySelectorAll('.rex-file-group')).some(group => !group.hidden);
                    folder.hidden = !hasFiles; if (hasFiles) folder.open = true;
                } else {
                    folder.hidden = false;
                    if (wasFiltered) folder.open = openState.get(folder);
                }
            });
            wasFiltered = filtering;
            status.textContent = visible.size + (visible.size === 1 ? ' bestand' : ' bestanden') + (filtering ? ' gevonden' : ' beschikbaar');
        }
        [search, category, type, favorite].forEach(el => el.addEventListener('input', filter));
        folders.forEach(folder => folder.addEventListener('toggle', () => { if (!wasFiltered) openState.set(folder, folder.open); }));
        root.querySelector('.rex-expand').addEventListener('click', () => folders.forEach(folder => { folder.open = true; openState.set(folder, true); }));
        root.querySelector('.rex-collapse').addEventListener('click', () => folders.forEach(folder => { folder.open = false; openState.set(folder, false); }));
        root.querySelectorAll('.rex-favorite').forEach(button => button.addEventListener('click', async () => {
            const id = button.dataset.id;
            const active = button.getAttribute('aria-pressed') !== 'true';
            const buttons = Array.from(root.querySelectorAll('.rex-favorite')).filter(b => b.dataset.id === id);
            buttons.forEach(b => b.disabled = true);
            try {
                const body = new URLSearchParams({action: 'rex_favorite', nonce: root.dataset.favoriteNonce, id, active: active ? '1' : '0'});
                const response = await fetch(root.dataset.ajaxUrl, {method: 'POST', credentials: 'same-origin', body});
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Favoriet opslaan mislukt.');
                groups.filter(group => group.dataset.id === id).forEach(group => group.dataset.favorite = active ? '1' : '0');
                buttons.forEach(b => { b.setAttribute('aria-pressed', String(active)); b.textContent = active ? '★ Favoriet' : '☆ Favoriet'; });
                filter();
            } catch (error) { status.textContent = error.message; }
            finally { buttons.forEach(b => b.disabled = false); }
        }));
        filter();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.rex-downloads').forEach(init));
    else document.querySelectorAll('.rex-downloads').forEach(init);
}());
