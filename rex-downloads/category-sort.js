jQuery(function ($) {
    const table = $('#rex-category-sort');
    let snapshot;
    const ids = () => table.children('tr').map(function () { return Number(this.dataset.termId); }).get();
    const serialize = () => $('#rex-sort-order').val(JSON.stringify(ids()));
    table.sortable({
        items: '> tr', handle: '.rex-drag-handle', axis: 'y',
        start: function () { snapshot = table.children('tr').toArray(); },
        update: function (event, ui) {
            const moved = ui.item[0];
            const parent = moved.dataset.parentId;
            const before = snapshot.filter(row => row.dataset.parentId === parent).map(row => row.dataset.termId);
            const after = table.children('tr').toArray().filter(row => row.dataset.parentId === parent).map(row => row.dataset.termId);
            // Keep the complete hierarchy together: rebuild preorder using reordered siblings.
            const maps = new Map();
            snapshot.forEach(row => { if (!maps.has(row.dataset.parentId)) maps.set(row.dataset.parentId, []); maps.get(row.dataset.parentId).push(row); });
            maps.set(parent, after.map(id => snapshot.find(row => row.dataset.termId === id)));
            const seen = new Set();
            function append(parentId) {
                (maps.get(parentId) || []).forEach(row => { if (seen.has(row)) return; seen.add(row); table.append(row); append(row.dataset.termId); });
            }
            append('0'); snapshot.filter(row => !seen.has(row)).forEach(row => table.append(row));
            serialize();
            $('#rex-sort-message').text(before.join(',') === after.join(',') ? 'Versleep naast een categorie van hetzelfde niveau.' : 'Volgorde gewijzigd. Klik op Volgorde opslaan.');
        }
    });
    $('#rex-sort-form').on('submit', serialize);
    serialize();
});
