$( document ).ready(function() {
    $('.bolt_poll button').bind('click.boltpoll', function(){
        var poll = $(this).closest('.bolt_poll').attr('id');
        $.ajax({
            url: boltpolls_url,
            type: 'get',
            data: {'vote': $(this).attr('value') },
            success: function (data) {
                $('#'+poll).replaceWith(data);
            }
        });
    });
});
