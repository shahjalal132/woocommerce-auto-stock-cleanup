jQuery(document).ready(function($) {
    $('#delete-images-btn').on('click', function() {
        const idsRaw = $('#image-ids').val().trim();
        if (!idsRaw) return alert('Please enter image IDs.');

        const ids = idsRaw.split(',').map(id => id.trim()).filter(id => id);
        let deletedCount = 0;

        $('#progress-wrapper').show();
        $('#progress-bar').css({
            'width': '0%',
            'height': '20px',
            'background': '#0073aa'
        });
        $('#progress-text').text('0%');
        $('#result').html('');

        function deleteNext() {
            if (ids.length === 0) {
                $('#result').html(`<p><strong>Done!</strong> Deleted ${deletedCount} image(s).</p>`);
                return;
            }

            const id = ids.shift();
            $.post(deleteImages.ajax_url, {
                action: 'delete_images_by_ids',
                nonce: deleteImages.nonce,
                ids: id
            }, function(response) {
                if (response.success) {
                    deletedCount += response.deleted.length;
                }

                const progress = Math.round((deletedCount / (deletedCount + ids.length)) * 100);
                $('#progress-bar').css('width', progress + '%');
                $('#progress-text').text(progress + '%');

                deleteNext();
            });
        }

        deleteNext();
    });
});

