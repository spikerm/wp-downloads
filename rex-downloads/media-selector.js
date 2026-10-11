jQuery(function ($) {
    var frame;
    var filename = '';
    function applyFilename() {
        if (filename && $('#rex-use-filename').prop('checked')) {
            $('#rex-download-title').val(filename).trigger('input');
        }
    }
    $('#rex-media-select').on('click', function () {
        if (!frame) {
            frame = wp.media({title: 'Kies een downloadbestand', button: {text: 'Dit bestand gebruiken'}, multiple: false});
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                filename = attachment.filename || attachment.title || '';
                $('#rex-media-id').val(attachment.id);
                $('#rex-media-name').text(filename);
                applyFilename();
            });
        }
        frame.open();
    });
    $('#rex-use-filename').on('change', applyFilename);
    $('#rex-media-clear').on('click', function () {
        filename = '';
        $('#rex-media-id').val(0);
        $('#rex-media-name').text('');
    });
});
