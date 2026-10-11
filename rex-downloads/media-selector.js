jQuery(function ($) {
    let frame;
    let filename = '';
    const editing = Number($('#rex-edit-id').val()) > 0;
    function applyFilename() {
        if (filename && $('#rex-use-filename').prop('checked')) $('#rex-download-title').val(filename).trigger('input');
    }
    $('#rex-media-select').on('click', function () {
        if (!frame) {
            frame = wp.media({title: editing ? 'Kies een downloadbestand' : 'Kies één of meerdere bestanden (maximaal 50)', button: {text: 'Bestanden gebruiken'}, multiple: !editing});
            frame.on('select', function () {
                const selected = frame.state().get('selection').toJSON();
                if (selected.length > 50) { window.alert('Selecteer maximaal 50 bestanden.'); return; }
                if (!selected.length) return;
                filename = selected[0].filename || selected[0].title || '';
                $('#rex-media-id').val(selected[0].id);
                $('#rex-media-ids').val(JSON.stringify(selected.map(a => a.id)));
                $('#rex-media-name').text(selected.length > 1 ? selected.length + ' bestanden geselecteerd — elke bestandsnaam wordt de eigen titel' : filename);
                if (selected.length > 1) $('#rex-download-title').val('Bulkimport: ' + selected.length + ' bestanden');
                else applyFilename();
            });
        }
        frame.open();
    });
    $('#rex-use-filename').on('change', applyFilename);
    $('#rex-media-clear').on('click', function () {
        filename = ''; $('#rex-media-id').val(0); $('#rex-media-ids').val(''); $('#rex-media-name').text('');
    });
});
