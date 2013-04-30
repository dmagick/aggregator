<script type="text/javascript">
$(document).ready(function () {

    function processStatusChange(spanid, newstatus) {
        url = spanid.id;
        var request = $.ajax({
            url: '~url::baseurl~/feed/' + newstatus + '/' + url,
            type: 'post'
        });
    }

    $('span.urlstatus').on('click', function() {
        if ($(this).hasClass('urlMarkRead')) {
            processStatusChange(this, 'mark');
            $(this).removeClass('urlMarkRead').addClass('urlMarkUnRead');
            $(this).text('UnMark');
        } else {
            processStatusChange(this, 'unmark');
            $(this).removeClass('urlMarkUnRead').addClass('urlMarkRead');
            $(this).text('Mark');
        }
    });
});

</script>

